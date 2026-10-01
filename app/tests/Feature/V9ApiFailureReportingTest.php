<?php

namespace Tests\Feature;

use App\Models\ApiFailureIncident;
use App\Services\Operations\ApiFailureFingerprint;
use App\Services\Operations\ApiFailureReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V9ApiFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_and_expected_statuses_are_not_reported(): void
    {
        $reporter = app(ApiFailureReporter::class);
        $reporter->observeApiFailure('GET', '/api/health', 200, 'ok');
        $reporter->observeApiFailure('GET', '/api/lookup', 404, 'not found', ['skip_reporting' => true]);

        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
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
    }
}
