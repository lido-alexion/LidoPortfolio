<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalAiInvocation;
use App\Services\Fundamentals\AIInsightsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FundamentalAiUsageLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_per_user_limit_returns_rate_limited_without_calling_provider(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.limits.daily_global_max' => 0,
            'fundamentals_ai.limits.daily_per_user_max' => 1,
            'fundamentals_ai.gemini.api_key' => 'test-key',
        ]);

        Http::fake();

        $user = User::factory()->create();
        $stock = Stock::query()->create(['symbol' => 'LIM', 'exchange' => 'NSE', 'name' => 'Limit Co']);
        $this->defaultPortfolioFor($user);

        FundamentalAiInvocation::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'feature_key' => 'fundamental_signals',
            'status' => 'ok',
            'latency_ms' => 10,
            'created_at' => now(),
        ]);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk()
            ->assertJsonPath('data.insights.ai.status', 'rate_limited');

        Http::assertNothingSent();
    }

    public function test_successful_ai_call_is_logged(): void
    {
        config([
            'fundamentals_ai.enabled' => true,
            'fundamentals_ai.limits.daily_per_user_max' => 0,
            'fundamentals_ai.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'summary' => 'Logged test summary.',
                                'positive_signals' => [],
                                'risk_signals' => [],
                                'watch_items' => [],
                                'follow_up_checks' => [],
                                'data_sufficiency' => ['rating' => 'low', 'missing_information' => []],
                            ]),
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 8],
            ], 200),
        ]);

        $user = User::factory()->create();
        $stock = Stock::query()->create(['symbol' => 'LOG', 'exchange' => 'NSE', 'name' => 'Log Co']);
        $this->defaultPortfolioFor($user);

        app(AIInsightsService::class)->enrich($stock, Carbon::now(), [
            'summary' => 'Deterministic only.',
            'positive_signals' => [],
            'risk_signals' => [],
            'watch_items' => [],
        ], $user);

        $this->assertDatabaseHas('stox_fundamental_ai_invocations', [
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'status' => 'ok',
            'provider' => 'gemini',
            'input_tokens' => 12,
            'output_tokens' => 8,
            'estimated_cost_usd' => 0.000004,
        ]);
    }

    public function test_admin_usage_summary_includes_token_and_cost_totals(): void
    {
        config([
            'fundamentals_ai.limits.daily_global_max' => 100,
        ]);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        FundamentalAiInvocation::query()->create([
            'user_id' => $admin->id,
            'feature_key' => 'fundamental_signals',
            'status' => 'ok',
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'estimated_cost_usd' => 0.10,
            'latency_ms' => 1,
            'created_at' => now(),
        ]);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/fundamentals')
            ->assertOk()
            ->assertJsonPath('data.ai_insights.usage.today.input_tokens', 1000000)
            ->assertJsonPath('data.ai_insights.usage.today.estimated_cost_usd', 0.1);
    }
}
