<?php

namespace Tests\Feature\Execution;

use App\Models\BrokerConnection;
use App\Models\User;
use App\Services\Broker\KiteBrokerGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KiteReadOnlySmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broker.kite.api_key' => 'synthetic-key', 'broker.kite.api_secret' => 'synthetic-secret', 'broker.kite.api_base' => 'https://api.kite.trade']);
        Http::preventStrayRequests();
    }

    private function connectedUser(bool $expired = false): User
    {
        $user = User::factory()->create();
        $connection = new BrokerConnection(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => $expired ? now()->subMinute() : now()->addHour()]);
        $connection->access_token = 'synthetic-token-never-print';
        $connection->save();
        return $user;
    }

    private function providerResponses(int $positionsStatus = 200): void
    {
        Http::fake([
            'https://api.kite.trade/portfolio/holdings' => Http::response(['status' => 'success', 'data' => []]),
            'https://api.kite.trade/portfolio/positions' => Http::response(['status' => $positionsStatus === 200 ? 'success' : 'error', 'data' => ['net' => []]], $positionsStatus),
            'https://api.kite.trade/user/margins/equity' => Http::response(['status' => 'success', 'data' => ['available' => ['live_balance' => 100]]]),
        ]);
    }

    public function test_contract_reads_all_three_boundaries_without_database_writes_or_live_evidence_claim(): void
    {
        $user = $this->connectedUser();
        $this->providerResponses();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $exit = Artisan::call('stox:kite-read-only-smoke', ['user_id' => $user->id]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertTrue($report['passed']);
        $this->assertSame('deterministic_contract', $report['evidence_kind']);
        $this->assertSame(3, $report['read_requests']);
        $this->assertStringNotContainsString('synthetic-token-never-print', $output);
        $this->assertArrayNotHasKey('current_cash', $report);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.kite.trade/portfolio/holdings');
        foreach (DB::getQueryLog() as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
        }
        DB::disableQueryLog();
    }

    public function test_expired_session_stops_before_provider_requests(): void
    {
        $user = $this->connectedUser(expired: true);
        Http::fake();
        $this->artisan('stox:kite-read-only-smoke', ['user_id' => $user->id])->expectsOutputToContain('"stage":"session"')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_failed_positions_cannot_be_qualified_as_empty_success(): void
    {
        $user = $this->connectedUser();
        $this->providerResponses(500);
        $this->artisan('stox:kite-read-only-smoke', ['user_id' => $user->id])->expectsOutputToContain('"passed":false')->assertFailed();
        Http::assertSentCount(3);
    }

    public function test_nonofficial_configuration_stops_before_provider_requests(): void
    {
        $user = $this->connectedUser();
        config(['broker.kite.api_base' => 'https://example.invalid']);
        Http::fake();
        $this->artisan('stox:kite-read-only-smoke', ['user_id' => $user->id])->expectsOutputToContain('"stage":"configuration"')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_unexpected_order_write_is_rejected_before_transmission(): void
    {
        $user = $this->connectedUser();
        Http::fake();
        $gateway = Mockery::mock(KiteBrokerGateway::class);
        $gateway->shouldReceive('portfolioSnapshot')->once()->andReturnUsing(fn () => Http::post('https://api.kite.trade/orders/regular', ['quantity' => 1]));
        $this->app->instance(KiteBrokerGateway::class, $gateway);
        $this->artisan('stox:kite-read-only-smoke', ['user_id' => $user->id])->expectsOutputToContain('"passed":false')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_unexpected_external_read_is_rejected_before_transmission(): void
    {
        $user = $this->connectedUser();
        Http::fake();
        $gateway = Mockery::mock(KiteBrokerGateway::class);
        $gateway->shouldReceive('portfolioSnapshot')->once()->andReturnUsing(fn () => Http::get('https://example.invalid/portfolio/holdings'));
        $this->app->instance(KiteBrokerGateway::class, $gateway);
        $this->artisan('stox:kite-read-only-smoke', ['user_id' => $user->id])->expectsOutputToContain('"passed":false')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_raw_provider_error_and_token_never_enter_console_output(): void
    {
        $user = $this->connectedUser();
        Http::fake();
        $gateway = Mockery::mock(KiteBrokerGateway::class);
        $gateway->shouldReceive('portfolioSnapshot')->once()->andThrow(new \RuntimeException('synthetic-token-never-print private provider response'));
        $this->app->instance(KiteBrokerGateway::class, $gateway);
        $this->assertSame(1, Artisan::call('stox:kite-read-only-smoke', ['user_id' => $user->id]));
        $output = Artisan::output();
        $this->assertStringNotContainsString('synthetic-token-never-print', $output);
        $this->assertStringNotContainsString('private provider response', $output);
        $this->assertSame('provider_reads', json_decode($output, true, flags: JSON_THROW_ON_ERROR)['stage']);
        Http::assertNothingSent();
    }
}
