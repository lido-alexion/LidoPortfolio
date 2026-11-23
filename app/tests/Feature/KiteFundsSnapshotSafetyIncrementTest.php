<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\KiteFundsReconciliation;
use App\Models\KiteFundsSnapshot;
use App\Models\User;
use App\Services\Broker\KiteFundsSnapshotService;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class KiteFundsSnapshotSafetyIncrementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();
        \App\Support\TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    private function account(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        return [$user, $profile];
    }

    private function snapshot(int $profileId, int $userId, string $key, array $overrides = []): KiteFundsSnapshot
    {
        return KiteFundsSnapshot::query()->create(array_merge([
            'profile_id' => $profileId,
            'user_id' => $userId,
            'broker_account_id' => 'AB1234',
            'trading_date' => '2026-11-20',
            'captured_at' => '2026-11-20 23:55:00',
            'available_balance' => 100,
            'opening_balance' => 100,
            'intraday_payin' => '500.0000',
            'utilised_payout' => '100.0000',
            'payout_semantics_verified' => true,
            'capture_source' => 'late_day',
            'freshness' => 'complete',
            'idempotency_key' => hash('sha256', $key),
            'provenance' => ['fixture' => true],
        ], $overrides));
    }

    public function test_partial_manual_transfers_with_a_net_mismatch_never_post_the_residual(): void
    {
        [$user, $profile] = $this->account();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        $this->snapshot($profile->id, $user->id, 'manual-partial');

        $cash = app(CashManagementService::class);
        foreach ([
            $cash->deposit($profile, '250.0000', 'Partial pay-in one', $user, '2026-11-20'),
            $cash->deposit($profile, '100.0000', 'Partial pay-in two', $user, '2026-11-20'),
            $cash->withdraw($profile, '50.0000', 'Partial payout', $user, '2026-11-20'),
        ] as $entry) {
            $entry->forceFill(['broker_account_id' => 'AB1234'])->save();
        }

        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();

        $row = KiteFundsReconciliation::query()->firstOrFail();
        $this->assertSame('manual_mismatch', $row->status);
        $this->assertSame('400.0000', $row->snapshot_net_flow);
        $this->assertSame('300.0000', $row->manual_net_flow);
        $this->assertNull($row->cash_ledger_entry_id);
        $this->assertSame(3, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, \App\Models\CashLedgerEntry::query()->where('entry_classification', 'kite_net_adjustment')->count());

        Date::setTestNow();
    }

    public function test_duplicate_complete_snapshots_choose_newest_final_evidence_and_reconcile_only_once(): void
    {
        [$user, $profile] = $this->account();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        $this->snapshot($profile->id, $user->id, 'duplicate-older-complete', [
            'captured_at' => '2026-11-20 23:40:00',
            'intraday_payin' => '900.0000', 'utilised_payout' => '300.0000',
        ]);
        $newest = $this->snapshot($profile->id, $user->id, 'duplicate-newer-complete', [
            'captured_at' => '2026-11-20 23:55:00',
            'intraday_payin' => '700.0000', 'utilised_payout' => '300.0000',
        ]);

        $service = app(KiteFundsSnapshotService::class);
        $service->reconcilePreviousSession();
        $service->reconcilePreviousSession();

        $row = KiteFundsReconciliation::query()->firstOrFail();
        $this->assertSame($newest->id, $row->snapshot_id);
        $this->assertSame('400.0000', $row->snapshot_net_flow);
        $this->assertSame('adjustment_applied', $row->status);
        $this->assertSame(1, KiteFundsReconciliation::query()->count());
        $this->assertSame(1, \App\Models\CashLedgerEntry::query()->where('entry_classification', 'kite_net_adjustment')->count());
        $this->assertSame('400.0000', \App\Models\CashLedgerEntry::query()->where('entry_classification', 'kite_net_adjustment')->value('amount'));

        Date::setTestNow();
    }

    public function test_previous_session_rolls_back_across_weekend_and_configured_holiday(): void
    {
        [$user, $profile] = $this->account();
        Date::setTestNow('2026-11-24 09:20:00 Asia/Kolkata');
        \App\Support\TradingCalendar::clearHolidayCache();
        CalendarEvent::query()->create([
            'profile_id' => null,
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Offline fixture holiday',
            'anchor_date' => '2026-11-23',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true,
        ]);
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        $friday = $this->snapshot($profile->id, $user->id, 'holiday-friday', [
            'intraday_payin' => '250.0000', 'utilised_payout' => '50.0000',
        ]);

        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();

        $row = KiteFundsReconciliation::query()->firstOrFail();
        $this->assertSame('2026-11-20', $row->trading_date->toDateString());
        $this->assertSame($friday->id, $row->snapshot_id);
        $this->assertSame('adjustment_applied', $row->status);
        $this->assertSame('200.0000', $row->snapshot_net_flow);

        Date::setTestNow();
        \App\Support\TradingCalendar::clearHolidayCache();
    }
}
