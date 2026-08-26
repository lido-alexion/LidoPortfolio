<?php

namespace Tests\Feature\Notification;

use App\Engines\Notification\NotificationEngine;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TosNotification;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\Lending\RecallNotificationService;
use App\Services\ProfileSettingsService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * V3 §30 — actionable UNFUNDED OPEN must notify; HOLD/WATCH must still skip.
 */
class Section30RecommendationNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $telegram = Mockery::mock(TelegramNotificationService::class);
        $telegram->shouldReceive('sendMessageForProfile')->andReturn(true)->byDefault();
        $this->app->instance(TelegramNotificationService::class, $telegram);
    }

    public function test_notify_recommendations_sends_for_unfunded_open_and_skips_hold(): void
    {
        [$profile, $stock] = $this->portfolioWithStock();
        $this->enableTelegram($profile);

        $open = $this->makeRec($profile, $stock, TradingRecommendation::ACTION_OPEN_POSITION, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 0,
        ]);
        $hold = $this->makeRec($profile, $stock, TradingRecommendation::ACTION_HOLD_POSITION);

        $engine = app(NotificationEngine::class);
        $delivered = $engine->notifyRecommendations($profile, [$open, $hold]);

        $this->assertCount(1, $delivered);
        $this->assertSame($open->id, $delivered[0]->recommendation_id);
        $this->assertSame('recommendation', $delivered[0]->notification_type);
        $msg = (string) ($delivered[0]->payload['message'] ?? '');
        $this->assertStringContainsString('OPEN_POSITION', $msg);
        $this->assertStringContainsString('Capital required', $msg);

        $this->assertSame(0, TosNotification::query()
            ->where('recommendation_id', $hold->id)
            ->count());
    }

    public function test_notify_recommendations_skips_watch(): void
    {
        [$profile, $stock] = $this->portfolioWithStock();
        $this->enableTelegram($profile);
        $watch = $this->makeRec($profile, $stock, TradingRecommendation::ACTION_WATCH);

        $delivered = app(NotificationEngine::class)->notifyRecommendations($profile, [$watch]);
        $this->assertSame([], $delivered);
        $this->assertSame(0, TosNotification::query()->count());
    }

    public function test_capital_required_domain_notify_for_unfunded_open(): void
    {
        [$profile, $stock] = $this->portfolioWithStock();
        $this->enableTelegram($profile);
        $open = $this->makeRec($profile, $stock, TradingRecommendation::ACTION_OPEN_POSITION, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 15_000,
            'allocated_amount' => 0,
        ]);

        app(RecallNotificationService::class)->capitalRequired($profile, $open);

        $row = TosNotification::query()->where('notification_type', 'capital_required')->first();
        $this->assertNotNull($row);
        $this->assertSame($open->id, $row->recommendation_id);
        $this->assertStringContainsString('Capital required', (string) ($row->payload['message'] ?? ''));
        $this->assertStringContainsString('not a HOLD/WATCH skip', (string) ($row->payload['message'] ?? ''));
    }

    public function test_capital_required_skips_hold_even_if_called(): void
    {
        [$profile, $stock] = $this->portfolioWithStock();
        $this->enableTelegram($profile);
        $hold = $this->makeRec($profile, $stock, TradingRecommendation::ACTION_HOLD_POSITION, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 10_000,
            'allocated_amount' => 0,
        ]);

        app(RecallNotificationService::class)->capitalRequired($profile, $hold);

        $this->assertSame(0, TosNotification::query()->where('notification_type', 'capital_required')->count());
    }

    public function test_exit_with_portfolio_sl_attribution_included_in_recommendation_message(): void
    {
        [$profile, $stock] = $this->portfolioWithStock();
        $this->enableTelegram($profile);
        $exit = $this->makeRec($profile, $stock, TradingRecommendation::ACTION_EXIT_POSITION);
        $plan = is_array($exit->execution_plan) ? $exit->execution_plan : [];
        $plan['primary_exit_reason'] = 'stop_loss';
        $exit->forceFill(['execution_plan' => $plan])->save();

        $delivered = app(NotificationEngine::class)->notifyRecommendations($profile, [$exit->fresh()]);
        $this->assertCount(1, $delivered);
        $this->assertStringContainsString('Portfolio stop-loss', (string) ($delivered[0]->payload['message'] ?? ''));
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock}
     */
    private function portfolioWithStock(): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, '§30 Notify', true);
        $stock = Stock::query()->create([
            'symbol' => 'N'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Notify Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$profile, $stock];
    }

    /**
     * @param  array{status?: string, target_amount?: float, allocated_amount?: float}|null  $capital
     */
    private function makeRec(
        PortfolioProfile $profile,
        Stock $stock,
        string $action,
        ?array $capital = null,
    ): TradingRecommendation {
        $plan = [];
        if ($capital !== null) {
            $plan['target_investment_amount'] = $capital['target_amount'] ?? 0;
            $plan['suggested_investment_amount'] = $capital['allocated_amount'] ?? 0;
            $plan['capital_allocation'] = [
                'status' => $capital['status'],
                'target_amount' => $capital['target_amount'] ?? 0,
                'allocated_amount' => $capital['allocated_amount'] ?? 0,
            ];
        }

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'recommendation_type' => $action,
            'status' => in_array($action, TradingRecommendation::ACTIONABLE_ACTIONS, true)
                ? TradingRecommendation::STATUS_PENDING_REVIEW
                : TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
            'execution_plan' => $plan,
            'version' => 1,
        ]);
    }

    private function enableTelegram(PortfolioProfile $profile): void
    {
        $settings = app(ProfileSettingsService::class);
        $settings->set($profile, 'notifications_enabled', 'true');
        $settings->set($profile, 'telegram_bot_token', 'test-token');
        $settings->set($profile, 'telegram_chat_id', '12345');
    }
}
