<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FundamentalExchangeRouteAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_and_enable_approved_normalized_feed_routes(): void
    {
        config(['fundamentals_bootstrap.approved_feed_hosts' => ['feeds.example']]);
        Http::fake();

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $client = $this->actingAs($admin)->withProfileHeader($admin);

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'request_delay_ms' => 750,
            'max_attempts' => 3,
            'paused' => false,
            'nse_official_feed_url' => 'https://feeds.example/nse/fundamentals',
            'nse_official_fallback_enabled' => true,
            'bse_official_feed_url' => 'https://feeds.example/bse/fundamentals',
            'bse_official_fallback_enabled' => true,
        ])->assertOk();

        $client->getJson('/api/v1/admin/fundamentals')->assertOk()
            ->assertJsonPath('data.settings.nse_official_feed_url', 'https://feeds.example/nse/fundamentals')
            ->assertJsonPath('data.settings.bse_official_feed_url', 'https://feeds.example/bse/fundamentals')
            ->assertJsonPath('data.exchange_fallbacks.nse.active', true)
            ->assertJsonPath('data.exchange_fallbacks.bse.active', true)
            ->assertJsonPath('data.exchange_fallbacks.bse.route_mode', 'normalized_feed');

        Http::assertNothingSent();
    }

    public function test_admin_route_rejects_unapproved_hosts_and_non_https_urls(): void
    {
        config(['fundamentals_bootstrap.approved_feed_hosts' => ['feeds.example']]);
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $client = $this->actingAs($admin)->withProfileHeader($admin);

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'nse_official_feed_url' => 'https://unapproved.example/nse',
            'nse_official_fallback_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('nse_official_feed_url');

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'bse_official_feed_url' => 'http://feeds.example/bse',
            'bse_official_fallback_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('bse_official_feed_url');

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'nse_official_feed_url' => 'https://127.0.0.1/internal',
            'nse_official_fallback_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('nse_official_feed_url');

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'bse_official_feed_url' => 'https://feeds.example/bse?token=do-not-store',
            'bse_official_fallback_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('bse_official_feed_url');

        $this->assertFalse((bool) FundamentalSetting::query()->first()?->nse_official_fallback_enabled);
        $this->assertFalse((bool) FundamentalSetting::query()->first()?->bse_official_fallback_enabled);
    }

    public function test_bse_fetch_uses_the_saved_admin_route_and_normalizes_its_fact_rows(): void
    {
        config(['fundamentals_bootstrap.approved_feed_hosts' => ['feeds.example']]);
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $this->actingAs($admin)->withProfileHeader($admin)
            ->putJson('/api/v1/admin/fundamentals/settings', [
                'quarterly_freshness_months' => 5,
                'annual_freshness_months' => 15,
                'bse_official_feed_url' => 'https://feeds.example/bse/fundamentals',
                'bse_official_fallback_enabled' => true,
            ])->assertOk();

        $stock = Stock::query()->create([
            'symbol' => 'ROUTECO',
            'exchange' => 'BSE',
            'name' => 'Route Test Company',
        ]);
        Http::fake([
            'feeds.example/*' => Http::response([
                'facts' => [[
                    'statement_type' => 'income_statement',
                    'cadence' => 'annual',
                    'statement_basis' => 'consolidated',
                    'fact_key' => 'revenue',
                    'period_end' => '2025-03-31',
                    'value' => 1200,
                    'currency' => 'INR',
                ]],
            ]),
        ]);

        $source = app(BseOfficialFundamentalHistoricalSource::class);
        $this->assertTrue($source->supports($stock));
        $rows = $source->fetch($stock, 'annual');

        $this->assertSame('revenue', $rows[0]['fact_key']);
        $this->assertSame(1200, $rows[0]['value']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://feeds.example/bse/fundamentals?'));
    }
}
