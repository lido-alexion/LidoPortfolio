<?php

namespace Tests\Feature\Risk;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfileSettingsService;
use App\Services\Risk\ExitAttribution;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Phase 3 — Settings / recommendation / transaction presentation contracts.
 */
class PortfolioRiskPhase3PresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_settings_get_returns_independent_sl_and_trailing_defaults(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.default_stoploss_percent', '10')
            ->assertJsonPath('data.portfolio_trailing_percent', '15');
    }

    public function test_settings_update_preserves_independent_sl_and_trailing(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/settings', [
                'default_stoploss_percent' => '8',
                'portfolio_trailing_percent' => '22',
            ])
            ->assertOk()
            ->assertJsonPath('data.default_stoploss_percent', '8')
            ->assertJsonPath('data.portfolio_trailing_percent', '22');

        $settings = app(ProfileSettingsService::class);
        $this->assertSame('8', $settings->get($profile, 'default_stoploss_percent'));
        $this->assertSame('22', $settings->get($profile, 'portfolio_trailing_percent'));

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/settings', [
                'default_stoploss_percent' => '11',
            ])
            ->assertOk()
            ->assertJsonPath('data.default_stoploss_percent', '11')
            ->assertJsonPath('data.portfolio_trailing_percent', '22');
    }

    public function test_recommendation_api_exposes_primary_exit_attribution(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $stock = Stock::query()->create([
            'symbol' => 'P3R'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Phase3 Rec',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 20,
            'confidence' => 0.7,
            'risk_level' => 'medium',
            'execution_plan' => [
                'primary_exit_reason' => ExitAttribution::STOP_LOSS,
                'exit_attribution' => [
                    'primary_reason' => ExitAttribution::STOP_LOSS,
                    'also_true' => [ExitAttribution::TRAILING_STOP],
                ],
            ],
            'evidence' => [
                'exit_attribution' => [
                    'primary_reason' => ExitAttribution::STOP_LOSS,
                    'also_true' => [ExitAttribution::TRAILING_STOP],
                ],
            ],
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/recommendations/'.$rec->id)
            ->assertOk()
            ->assertJsonPath('data.primary_exit_reason', ExitAttribution::STOP_LOSS)
            ->assertJsonPath('data.exit_attribution.primary_reason', ExitAttribution::STOP_LOSS)
            ->assertJsonPath('data.exit_attribution.also_true.0', ExitAttribution::TRAILING_STOP);
    }

    public function test_transaction_api_exposes_persisted_exit_reason(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'P3T'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Phase3 Tx',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->subDays(5)->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $sell = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 90,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'exit_reason' => ExitAttribution::TRAILING_STOP,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 0,
            'avg_buy_price' => 0,
            'invested_amount' => 0,
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/transactions/'.$sell->id)
            ->assertOk()
            ->assertJsonPath('data.exit_reason', ExitAttribution::TRAILING_STOP);

        $list = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/transactions?scope=all')
            ->assertOk();

        $row = collect($list->json('data'))->firstWhere('id', $sell->id);
        $this->assertNotNull($row);
        $this->assertSame(ExitAttribution::TRAILING_STOP, $row['exit_reason'] ?? null);
    }
}
