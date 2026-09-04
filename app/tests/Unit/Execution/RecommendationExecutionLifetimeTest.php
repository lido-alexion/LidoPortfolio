<?php

namespace Tests\Unit\Execution;

use App\Models\CalendarEvent;
use App\Models\Stock;
use App\Models\TosNotification;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\Execution\RecommendationExecutionLifetime;
use App\Services\Execution\RecommendationExecutionNotificationService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationExecutionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_before_cutoff_session_is_day_zero_and_gets_two_opportunities(): void
    {
        $result = app(RecommendationExecutionLifetime::class)->derive(
            Carbon::parse('2026-09-04 09:00:00', 'Asia/Kolkata'),
            '15:30',
        );

        $this->assertSame('day_0', $result['anchor_class']);
        $this->assertSame('2026-09-04', $result['first_eligible_date']);
        $this->assertSame('2026-09-07', $result['second_eligible_date']);
        $this->assertSame('2026-09-07 15:30:00', $result['expires_at']->format('Y-m-d H:i:s'));
    }

    public function test_after_cutoff_skips_weekends_and_trade_holidays(): void
    {
        CalendarEvent::query()->create([
            'title' => 'Trade holiday',
            'anchor_date' => '2026-09-07',
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();

        $result = app(RecommendationExecutionLifetime::class)->derive(
            Carbon::parse('2026-09-04 16:00:00', 'Asia/Kolkata'),
            '15:30',
        );

        $this->assertSame('day_1', $result['anchor_class']);
        $this->assertSame('2026-09-08', $result['first_eligible_date']);
        $this->assertSame('2026-09-09', $result['second_eligible_date']);
    }

    public function test_due_gap_expires_but_in_flight_order_preserves_order_lifecycle(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Execution');
        $stock = Stock::query()->create([
            'symbol' => 'LIFE', 'name' => 'Lifetime', 'exchange' => 'NSE', 'is_active' => true,
        ]);
        $make = function () use ($profile, $stock): TradingRecommendation {
            return TradingRecommendation::query()->create([
                'profile_id' => $profile->id,
                'security_id' => $stock->id,
                'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
                'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
                'execution_plan' => ['suggested_quantity' => 2, 'suggested_investment_amount' => 200],
                'reference_price' => 100,
                'generated_at' => now()->subDays(4),
                'execution_expires_at' => now()->subMinute(),
            ]);
        };
        $expired = $make();
        $preserved = $make();
        TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $preserved->id,
            'security_id' => $stock->id,
            'side' => 'buy', 'quantity' => 2, 'order_type' => 'market',
            'status' => TradingOrder::STATUS_PENDING,
            'broker_status' => TradingOrder::BROKER_OPEN,
        ]);

        $this->assertSame(1, app(RecommendationExecutionLifetime::class)->expireDue());
        $this->assertSame(TradingRecommendation::STATUS_EXPIRED, $expired->fresh()->status);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $preserved->fresh()->status);
        $this->assertDatabaseHas('portfolio_tos_notifications', [
            'recommendation_id' => $expired->id,
            'notification_type' => 'recommendation_expired',
        ]);
    }

    public function test_approaching_expiry_warning_is_idempotent_for_investor_actionable_blocker(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Warning');
        $stock = Stock::query()->create([
            'symbol' => 'WARN', 'name' => 'Warning', 'exchange' => 'NSE', 'is_active' => true,
        ]);
        $row = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'remaining_target_amount' => 500,
            'first_eligible_execution_date' => '2026-09-04',
            'second_eligible_execution_date' => '2026-09-07',
            'execution_expires_at' => Carbon::parse('2026-09-07 15:30', 'Asia/Kolkata'),
        ]);
        $service = app(RecommendationExecutionNotificationService::class);

        $service->sendApproachingExpiry(Carbon::parse('2026-09-04 15:31', 'Asia/Kolkata'));
        $service->sendApproachingExpiry(Carbon::parse('2026-09-04 16:00', 'Asia/Kolkata'));

        $this->assertSame(1, TosNotification::query()
            ->where('recommendation_id', $row->id)
            ->where('notification_type', 'recommendation_approaching_expiry')
            ->count());
    }

    public function test_only_the_two_frozen_session_windows_are_execution_opportunities(): void
    {
        $recommendation = new TradingRecommendation([
            'first_eligible_execution_date' => '2026-09-04',
            'second_eligible_execution_date' => '2026-09-07',
        ]);
        $lifetime = app(RecommendationExecutionLifetime::class);

        $this->assertFalse($lifetime->isExecutionOpportunity($recommendation, Carbon::parse('2026-09-04 09:14', 'Asia/Kolkata')));
        $this->assertTrue($lifetime->isExecutionOpportunity($recommendation, Carbon::parse('2026-09-04 09:15', 'Asia/Kolkata')));
        $this->assertFalse($lifetime->isExecutionOpportunity($recommendation, Carbon::parse('2026-09-04 15:30', 'Asia/Kolkata')));
        $this->assertFalse($lifetime->isExecutionOpportunity($recommendation, Carbon::parse('2026-09-05 10:00', 'Asia/Kolkata')));
        $this->assertTrue($lifetime->isExecutionOpportunity($recommendation, Carbon::parse('2026-09-07 10:00', 'Asia/Kolkata')));
    }
}
