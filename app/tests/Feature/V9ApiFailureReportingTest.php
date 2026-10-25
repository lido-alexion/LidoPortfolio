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

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_failure_reporting.observed_provider_hosts' => [
            ...config('api_failure_reporting.observed_provider_hosts', []), 'provider.example',
        ]]);
    }

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

    public function test_browser_error_ingestion_is_separate_bounded_authenticated_and_not_ai_code_bug(): void
    {
        Bus::fake();
        config([
            'api_failure_reporting.enabled' => true,
            'api_failure_reporting.environments' => ['testing'],
            'api_failure_reporting.queue_connection' => 'database',
            'log_error_triage.build_sha' => 'abcdef123456',
            'log_error_triage.enabled' => true,
            'log_error_triage.environments' => ['testing'],
        ]);

        $this->postJson('/api/ops/browser-errors', ['type' => 'react_render'])->assertUnauthorized();
        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson('/api/ops/browser-errors', ['type' => 'react_render'])
            ->assertStatus(202);
        $this->assertDatabaseMissing('portfolio_profiles', ['user_id' => $user->id]);

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('browser_runtime', $incident->direction);
        $this->assertSame('browser.react-render', $incident->component);
        $this->assertSame('untrusted_browser_observation', $incident->error_category);
        $this->assertSame('Typed browser runtime observation; client error details omitted (build abcdef123456).', $incident->safe_message);
        $this->assertNull($incident->trace_id);
        $this->assertDatabaseCount('stox_log_error_triages', 0);
        Bus::assertDispatched(CreateOrLinkGitHubIssueJob::class, fn ($job) => $job->incidentId === $incident->id);

        $github = \Mockery::mock(\App\Services\Operations\GitHubIssueReporter::class);
        $github->shouldReceive('report')->once()->withArgs(function ($marker, $title, $body, $labels, $seen) {
            return str_starts_with($marker, '<!-- stox-browser-observation:')
                && $title === '[Auto][Browser] StoX observed browser.react-render'
                && str_contains($body, 'untrusted client signal, not a confirmed code bug')
                && ! str_contains($body, 'componentStack')
                && in_array('browser-observation', $labels, true);
        })->andReturn(['status' => 'linked', 'number' => 42, 'url' => 'https://github.com/lido-alexion/LidoPortfolio/issues/42']);
        (new CreateOrLinkGitHubIssueJob($incident->id))->handle($github);
        $this->assertSame(42, $incident->fresh()->github_issue_number);

        $this->postJson('/api/ops/browser-errors', ['type' => 'unknown', 'message' => 'Bearer secret'])
            ->assertUnprocessable();
        $this->postJson('/api/ops/browser-errors', [
            'type' => 'uncaught_exception', 'route' => '/notes/PrivateTitle', 'module' => 'Alice',
        ])->assertStatus(422);
        $this->postJson('/api/ops/browser-errors', ['type' => 'unhandled_rejection', 'extra' => str_repeat('x', 600)])
            ->assertStatus(413);
        $this->assertDatabaseCount('portfolio_api_failure_incidents', 1);
    }

    public function test_browser_error_ingestion_throttles_by_authenticated_account_without_portfolio_resolution(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/ops/browser-errors', ['type' => 'unhandled_rejection'])->assertStatus(202);
        }
        $this->postJson('/api/ops/browser-errors', ['type' => 'unhandled_rejection'])->assertTooManyRequests();

        $this->assertDatabaseMissing('portfolio_profiles', ['user_id' => $user->id]);
        $this->assertSame(30, ApiFailureIncident::query()->sole()->occurrence_count);
    }

    public function test_each_supported_browser_fault_type_remains_an_untrusted_observation(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['react_render', 'uncaught_exception', 'unhandled_rejection'] as $type) {
            $this->postJson('/api/ops/browser-errors', ['type' => $type])->assertStatus(202);
        }

        $rows = ApiFailureIncident::query()->where('direction', 'browser_runtime')->orderBy('id')->get();
        $this->assertSame([
            'browser.react-render', 'browser.uncaught-exception', 'browser.unhandled-rejection',
        ], $rows->pluck('component')->all());
        $this->assertSame(['untrusted_browser_observation'], $rows->pluck('error_category')->unique()->values()->all());
        $this->assertDatabaseCount('stox_log_error_triages', 0);
    }

    public function test_optional_nse_warmups_do_not_report_failures_or_disable_data_observation(): void
    {
        Http::fake(fn ($request) => Http::response([], parse_url($request->url(), PHP_URL_PATH) === '/'
            || str_ends_with($request->url(), '/api/marketStatus') ? 403 : 200));

        foreach ([
            [\App\Support\NseHttpClient::class, 'https://www.nseindia.com/api/historical/cm/equity'],
            [\App\Support\NseChartingHttpClient::class, 'https://charting.nseindia.com/v1/charts/symbolHistoricalData'],
        ] as [$factory, $dataUrl]) {
            $client = $factory::create();
            $this->assertTrue($client->get($dataUrl)->successful());
            Http::assertSent(fn ($request) => $request->url() === $dataUrl
                && ! in_array('1', (array) $request->header('X-StoX-Skip-Api-Failure-Reporting'), true));
        }

        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
    }

    public function test_real_nse_data_403_remains_reportable_after_failed_warmups(): void
    {
        Http::fake(fn () => Http::response([], 403));

        foreach ([
            [\App\Support\NseHttpClient::class, 'https://www.nseindia.com/api/historical/cm/equity'],
            [\App\Support\NseChartingHttpClient::class, 'https://charting.nseindia.com/v1/charts/symbolHistoricalData'],
        ] as [$factory, $dataUrl]) {
            $client = $factory::create();
            try { $client->get($dataUrl); } catch (\Illuminate\Http\Client\RequestException) {}
            Http::assertSent(fn ($request) => $request->url() === $dataUrl
                && ! in_array('1', (array) $request->header('X-StoX-Skip-Api-Failure-Reporting'), true));
        }

        $this->assertDatabaseCount('portfolio_api_failure_incidents', 2);
        foreach (ApiFailureIncident::all() as $incident) {
            $this->assertSame(403, $incident->http_status);
            $this->assertNotSame('https://nseindia.com/', $incident->endpoint);
            $this->assertSame(1, $incident->occurrence_count);
        }
    }

    public function test_external_http_observation_reports_non_2xx_but_not_2xx(): void
    {
        Http::fake([
            'https://provider.example/success' => Http::response([], 204),
            'https://provider.example/failure' => Http::response([], 503),
        ]);

        ExternalHttp::client()->get('https://provider.example/success');
        Http::get('https://provider.example/success');
        ExternalHttp::client()->get('https://provider.example/failure');

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('outbound_external', $incident->direction);
        $this->assertSame(503, $incident->http_status);
        $this->assertSame('https://provider.example/failure', $incident->endpoint);
        $this->assertSame(1, $incident->occurrence_count);
    }

    public function test_direct_and_wrapper_clients_share_response_and_connection_observation(): void
    {
        Http::fake([
            'https://provider.example/failure*' => Http::response([], 503),
            'https://provider.example/ok*' => Http::response([], 204),
        ]);
        Http::get('https://provider.example/failure?token=query-secret');
        ExternalHttp::client()->get('https://provider.example/failure?token=query-secret');
        Http::get('https://provider.example/ok');
        ExternalHttp::client()->get('https://provider.example/ok');

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(2, $incident->occurrence_count);
        $this->assertSame('https://provider.example/failure', $incident->endpoint);
        $this->assertStringNotContainsString('query-secret', $incident->endpoint.(string) $incident->safe_message);
    }

    public function test_direct_and_wrapper_clients_observe_connection_failures_without_exception_text(): void
    {
        Http::fake([
            'https://provider.example/connect' => Http::failedConnection('private host token=secret'),
        ]);
        try { Http::get('https://provider.example/connect'); } catch (\Illuminate\Http\Client\ConnectionException) {}
        try { ExternalHttp::client()->get('https://provider.example/connect'); } catch (\Illuminate\Http\Client\ConnectionException) {}

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(2, $incident->occurrence_count);
        $this->assertSame('ConnectionException', $incident->failure_class);
        $this->assertNull($incident->safe_message);
    }

    public function test_http_observer_handles_opt_out_arrays_and_excludes_secrets_and_reporter_traffic(): void
    {
        Http::fake(fn () => Http::response([], 503));
        Http::withHeaders(['X-StoX-Skip-Api-Failure-Reporting' => '1'])
            ->get('https://provider.example/failure?token=query-secret');
        Http::get('https://api.telegram.org/botPATH_SECRET/sendMessage?chat_id=1');
        Http::get('https://api.github.com/repos/lido-alexion/LidoPortfolio/issues');
        Http::get('https://private-tenant.example/private-route/Alice');
        Http::get('https://tenant.provider.example/private-route/Alice?token=query-secret');
        config(['ai_runtime.base_url' => 'https://nseindia.com']);
        try { app(\App\Services\AI\AiRuntimeClient::class)->capabilities(); } catch (\RuntimeException) {}

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(1, $incident->occurrence_count);
        $this->assertSame('https://provider.example/{resource}/{resource}', $incident->endpoint);
        $this->assertStringNotContainsString('tenant', $incident->endpoint);
        $this->assertStringNotContainsString('Alice', $incident->endpoint);
        $this->assertStringNotContainsString('query-secret', $incident->endpoint);
    }

    public function test_allowlisted_provider_write_failure_is_observed_with_safe_endpoint_only(): void
    {
        Http::fake(['https://api.nseindia.com/api/market/data*' => Http::response([], 503)]);
        Http::post('https://api.nseindia.com/api/market/data?account=private', ['private' => 'body-value']);

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('POST', $incident->method);
        $this->assertSame('https://nseindia.com/api/market/data', $incident->endpoint);
        $this->assertNull($incident->safe_message);
    }

    public function test_expected_endpoint_status_suppresses_framework_observation(): void
    {
        config(['api_failure_reporting.expected_endpoint_statuses' => ['https://nseindia.com/api/market/data' => [503]]]);
        Http::fake(['https://api.nseindia.com/api/market/data*' => Http::response([], 503)]);
        Http::post('https://api.nseindia.com/api/market/data');

        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
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
