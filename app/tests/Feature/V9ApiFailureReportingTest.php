<?php

namespace Tests\Feature;

use App\Models\ApiFailureIncident;
use App\Services\Operations\ApiFailureFingerprint;
use App\Services\Operations\ApiFailureReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use App\Jobs\CreateOrLinkGitHubIssueJob;
use App\Support\ExternalHttp;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Http\Middleware\ResolveActivePortfolio;
use Tests\TestCase;

class V9ApiFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_and_expected_statuses_are_not_reported(): void
    {
        $reporter = app(ApiFailureReporter::class);
        foreach ([200, 201, 202, 204] as $status) {
            $reporter->observeApiFailure('GET', '/api/health', $status, 'ok');
        }
        config(['api_failure_reporting.expected_statuses' => [404]]);
        $reporter->observeApiFailure('GET', '/api/lookup', 404, 'expected lookup miss');

        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
    }

    public function test_endpoint_policy_suppresses_only_its_normalized_expected_status(): void
    {
        config(['api_failure_reporting.expected_endpoint_statuses' => ['/api/lookup/{id}' => [404]]]);
        $reporter = app(ApiFailureReporter::class);
        $reporter->observeApiFailure('GET', '/api/lookup/123', 404, 'normal lookup miss');
        $reporter->observeApiFailure('GET', '/api/items/123', 404, 'unexpected missing item');

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('/api/items/{id}', $incident->endpoint);
        $this->assertSame(404, $incident->http_status);
    }

    public function test_failures_are_redacted_normalized_and_atomically_aggregated(): void
    {
        $reporter = app(ApiFailureReporter::class);
        $payload = ['message' => 'Bearer super-secret-token for user@example.com', 'trace_id' => 'req-1'];
        $reporter->observeApiFailure('GET', '/api/stocks/123/history?token=secret', 500, $payload['message'], $payload);
        $reporter->observeApiFailure('GET', '/api/stocks/456/history?token=other', 500, $payload['message'], ['trace_id' => 'req-2']);

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(2, $incident->occurrence_count);
        $this->assertSame('/api/stocks/{id}/history', $incident->endpoint);
        $this->assertStringNotContainsString('super-secret-token', (string) $incident->safe_message);
        $this->assertStringNotContainsString('user@example.com', (string) $incident->safe_message);
    }

    public function test_fingerprint_is_stable_for_volatile_route_values(): void
    {
        $fingerprint = app(ApiFailureFingerprint::class);
        $one = $fingerprint->make(['environment' => 'production', 'direction' => 'outbound_external', 'component' => 'nse', 'method' => 'GET', 'endpoint' => '/v8/finance/chart/RELIANCE.NS?id=1', 'http_status' => 503, 'error_category' => 'provider']);
        $two = $fingerprint->make(['environment' => 'production', 'direction' => 'outbound_external', 'component' => 'nse', 'method' => 'GET', 'endpoint' => '/v8/finance/chart/INFY.NS?id=2', 'http_status' => 503, 'error_category' => 'provider']);
        $this->assertSame($one, $two);
        $this->assertNotSame($one, $fingerprint->make(['environment' => 'production', 'direction' => 'outbound_external', 'component' => 'nse', 'method' => 'GET', 'endpoint' => '/v8/finance/chart/INFY.NS', 'http_status' => 500, 'error_category' => 'provider']));
    }

    public function test_frontend_failure_ingestion_is_authenticated_and_records_only_sanitized_stox_paths(): void
    {
        $payload = [
            'method' => 'GET',
            'endpoint' => '/api/stocks/RELIANCE.NS/history',
            'status' => 500,
            'message' => 'token=private-value account_id=acct-123 failed for user@example.com',
            'request_id' => 'request-safe-123',
        ];

        $this->postJson('/api/ops/api-failures', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);

        $this->withoutMiddleware(ResolveActivePortfolio::class)
            ->actingAs(User::factory()->create())
            ->postJson('/api/ops/api-failures', $payload)
            ->assertStatus(202);

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('frontend_internal', $incident->direction);
        $this->assertSame('/api/stocks/{symbol}/history', $incident->endpoint);
        $this->assertStringNotContainsString('private-value', $incident->safe_message);
        $this->assertStringNotContainsString('acct-123', $incident->safe_message);
        $this->assertStringNotContainsString('user@example.com', $incident->safe_message);
        $this->assertSame('request-safe-123', $incident->trace_id);
    }

    public function test_frontend_ingestion_rejects_external_urls(): void
    {
        $this->withoutMiddleware(ResolveActivePortfolio::class)
            ->actingAs(User::factory()->create())
            ->postJson('/api/ops/api-failures', [
                'method' => 'GET', 'endpoint' => 'https://attacker.example/api/secrets', 'status' => 500,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
    }

    public function test_frontend_ingestion_is_throttled(): void
    {
        $this->withoutMiddleware(ResolveActivePortfolio::class)->actingAs(User::factory()->create());
        $payload = ['method' => 'GET', 'endpoint' => '/api/safe', 'status' => 500];
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/ops/api-failures', $payload)->assertStatus(202);
        }
        $this->postJson('/api/ops/api-failures', $payload)->assertTooManyRequests();
    }

    public function test_external_http_observation_reports_non_2xx_but_not_2xx(): void
    {
        Http::fake([
            'https://provider.example/success' => Http::response([], 204),
            'https://provider.example/failure' => Http::response([], 503),
        ]);

        ExternalHttp::client()->get('https://provider.example/success');
        ExternalHttp::client()->get('https://provider.example/failure');

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('outbound_external', $incident->direction);
        $this->assertSame(503, $incident->http_status);
        $this->assertSame('https://provider.example/failure', $incident->endpoint);
    }

    public function test_queue_dispatch_failure_preserves_local_observation(): void
    {
        Bus::fake();
        config([
            'api_failure_reporting.enabled' => true,
            'api_failure_reporting.environments' => ['testing'],
            'api_failure_reporting.queue_connection' => 'missing-async-connection',
        ]);

        $incident = app(ApiFailureReporter::class)->observe([
            'direction' => 'outbound_external', 'component' => 'test-provider',
            'method' => 'GET', 'endpoint' => '/safe-route', 'http_status' => 503,
        ]);

        $this->assertNotNull($incident);
        $this->assertDatabaseHas('portfolio_api_failure_incidents', [
            'id' => $incident->id, 'sync_status' => 'pending', 'occurrence_count' => 1,
        ]);
        Bus::assertNotDispatched(CreateOrLinkGitHubIssueJob::class);
    }

    public function test_retention_prunes_only_expired_local_incidents(): void
    {
        $reporter = app(ApiFailureReporter::class);
        $expired = $reporter->observe(['direction' => 'outbound_external', 'component' => 'provider', 'method' => 'GET', 'endpoint' => '/expired', 'http_status' => 503]);
        $recent = $reporter->observe(['direction' => 'outbound_external', 'component' => 'provider', 'method' => 'GET', 'endpoint' => '/recent', 'http_status' => 502]);
        DB::table('portfolio_api_failure_incidents')->where('id', $expired->id)->update(['last_seen_at' => now()->subDays(91)]);

        Artisan::call('portfolio:purge-api-failure-incidents');

        $this->assertDatabaseMissing('portfolio_api_failure_incidents', ['id' => $expired->id]);
        $this->assertDatabaseHas('portfolio_api_failure_incidents', ['id' => $recent->id]);
    }
}
