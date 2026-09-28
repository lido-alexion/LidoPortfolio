<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FundamentalAiInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gemini_primary_returns_ai_ok_when_configured(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.gemini.api_key' => 'test-key',
            'fundamentals_ai.codex.api_key' => null,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'summary' => 'Material leverage and cash conversion risks dominate.',
                                'positive_signals' => [],
                                'risk_signals' => [],
                                'watch_items' => [],
                                'follow_up_checks' => [],
                                'data_sufficiency' => ['rating' => 'medium', 'missing_information' => []],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'AIG', 'exchange' => 'NSE', 'name' => 'AI Gen']);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk()
            ->assertJsonPath('data.insights.ai.status', 'ok')
            ->assertJsonPath('data.insights.ai.provider', 'gemini')
            ->assertJsonPath('data.insights.summary', 'Material leverage and cash conversion risks dominate.');
    }

    public function test_failover_to_codex_when_gemini_fails(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.primary_provider' => 'gemini',
            'fundamentals_ai.secondary_provider' => 'codex',
            'fundamentals_ai.gemini.api_key' => 'test-key',
            'fundamentals_ai.codex.api_key' => 'codex-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('rate limited', 429),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Secondary provider summary.',
                            'positive_signals' => [],
                            'risk_signals' => [],
                            'watch_items' => [],
                            'follow_up_checks' => [],
                            'data_sufficiency' => ['rating' => 'low', 'missing_information' => ['peers']],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'FO', 'exchange' => 'NSE', 'name' => 'Failover']);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk()
            ->assertJsonPath('data.insights.ai.status', 'ok')
            ->assertJsonPath('data.insights.ai.provider', 'codex')
            ->assertJsonPath('data.insights.summary', 'Secondary provider summary.');
    }

    public function test_prohibited_recommendation_output_is_rejected_without_breaking_fundamentals(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.gemini.api_key' => 'test-key',
            'fundamentals_ai.codex.api_key' => null,
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'summary' => 'Buy this stock.',
                        'positive_signals' => [],
                        'risk_signals' => [],
                    ])]]],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'SAFE', 'exchange' => 'NSE', 'name' => 'Safe Output']);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk()
            ->assertJsonPath('data.insights.ai.status', 'unavailable');
    }
}
