<?php

namespace Tests\Feature\V8;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FundamentalAiAdminDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_fundamentals_status_includes_ai_provider_diagnostics(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.gemini.api_key' => 'secret',
            'fundamentals_ai.codex.api_key' => null,
        ]);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/fundamentals')
            ->assertOk()
            ->assertJsonPath('data.ai_insights.enabled', true)
            ->assertJsonPath('data.ai_insights.primary_provider', 'gemini')
            ->assertJsonPath('data.ai_insights.primary_provider_source', 'env');
    }

    public function test_admin_can_persist_ai_primary_provider_override(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.primary_provider' => 'gemini',
            'fundamentals_ai.gemini.api_key' => 'secret',
            'fundamentals_ai.codex.api_key' => 'secret2',
        ]);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->putJson('/api/v1/admin/fundamentals/settings', [
                'quarterly_freshness_months' => 5,
                'annual_freshness_months' => 15,
                'request_delay_ms' => 750,
                'max_attempts' => 3,
                'paused' => false,
                'ai_insights_primary_provider' => 'codex',
            ])
            ->assertOk();

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/fundamentals')
            ->assertOk()
            ->assertJsonPath('data.ai_insights.primary_provider', 'codex')
            ->assertJsonPath('data.ai_insights.primary_provider_source', 'database')
            ->assertJsonPath('data.ai_insights.secondary_provider', 'gemini');
    }


    public function test_admin_can_control_exchange_fallbacks_only_when_route_is_configured(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse',
            'fundamentals_bootstrap.bse_official_feed_url' => null,
            'fundamentals_bootstrap.nse_official_direct_enabled' => false,
            'fundamentals_bootstrap.nse_official_direct_access_authorized' => false,
        ]);
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $client = $this->actingAs($admin)->withProfileHeader($admin);

        $client->getJson('/api/v1/admin/fundamentals')->assertOk()
            ->assertJsonPath('data.exchange_fallbacks.nse.enabled', false)
            ->assertJsonPath('data.exchange_fallbacks.nse.configured', true)
            ->assertJsonPath('data.exchange_fallbacks.nse.active', false)
            ->assertJsonPath('data.exchange_fallbacks.bse.configured', false);

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'nse_official_fallback_enabled' => true,
        ])->assertOk();
        $client->getJson('/api/v1/admin/fundamentals')->assertOk()
            ->assertJsonPath('data.exchange_fallbacks.nse.enabled', true)
            ->assertJsonPath('data.exchange_fallbacks.nse.active', true);

        $client->putJson('/api/v1/admin/fundamentals/settings', [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'bse_official_fallback_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('bse_official_fallback_enabled');
    }

    public function test_admin_can_probe_ai_provider(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'summary' => 'Probe ok.',
                                'positive_signals' => [],
                                'risk_signals' => [],
                                'watch_items' => [],
                                'follow_up_checks' => [],
                                'data_sufficiency' => ['rating' => 'low', 'missing_information' => []],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $stock = \App\Models\Stock::query()->create(['symbol' => 'PROBE', 'exchange' => 'NSE', 'name' => 'Probe']);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/fundamentals/ai-insights/test', [
                'stock_id' => $stock->id,
                'provider' => 'gemini',
            ])
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.provider', 'gemini');
    }
}
