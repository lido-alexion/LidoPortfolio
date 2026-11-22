<?php

namespace App\Services\Execution;

use App\Models\KiteSessionEvidence;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Models\TradingOrder;
use App\Models\User;
use App\Services\Broker\BrokerGateway;
use App\Services\CashManagementService;
use Illuminate\Support\Carbon;
use Throwable;

/** Shadow-only, account-scoped evidence capture. This service never changes execution gates. */
final class KiteSessionEvidenceService
{
    public function __construct(private BrokerGateway $broker, private CashManagementService $cash) {}

    public function capture(User $user): array
    {
        $connection = BrokerConnection::query()->where('user_id', $user->id)->where('provider', 'kite')->first();
        if (! $connection || ! $connection->isUsable() || ! $connection->broker_user_id) {
            return ['status' => 'unknown', 'reason' => 'kite_session_unavailable'];
        }

        $snapshot = null;
        try {
            $snapshot = $this->broker->portfolioSnapshot((int) $user->id);
        } catch (Throwable) {
            // Keep diagnostics credential-safe; provider exception text is intentionally not persisted.
        }

        $now = now();
        $sessionDate = $now->copy()->timezone('Asia/Kolkata')->toDateString();
        try {
            $captured = $snapshot !== null && isset($snapshot['captured_at'])
                ? Carbon::parse($snapshot['captured_at']) : null;
        } catch (Throwable) {
            $captured = null;
        }
        // Provider timestamps in the future are invalid, not fresh. Also reject a
        // snapshot without a timestamp: the gateway should supply one explicitly.
        $fresh = $snapshot !== null && $captured !== null && ! $captured->isFuture()
            && $captured->diffInSeconds($now) <= 300;
        $usableSnapshot = $fresh ? $snapshot : null;
        $profiles = PortfolioProfile::query()->where('user_id', $user->id)
            ->where('portfolio_type', PortfolioProfile::TYPE_LIVE)->get();
        $results = [];
        foreach ($profiles as $profile) {
            $orders = TradingOrder::query()->where('profile_id', $profile->id)
                ->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)->get();
            $ledgerCash = null;
            try {
                $summary = $this->cash->readSummary($profile);
                $ledgerCash = $summary['data']['cash_balance'] ?? null;
            } catch (Throwable) {}
            $reasons = [];
            if (! $snapshot) $reasons[] = 'broker_snapshot_missing';
            if ($snapshot && ! $fresh) $reasons[] = $captured === null ? 'broker_snapshot_timestamp_invalid' : 'broker_snapshot_stale';
            $reasons[] = 'order_book_api_not_captured';
            $reasons[] = 'trade_book_api_not_captured';
            $reasons[] = 'settlement_comparability_unproven';
            $evidence = [
                'stox_ledger' => ['cash_balance' => $ledgerCash],
                'kite_buying_power' => ['equity_available' => $usableSnapshot['current_cash'] ?? null],
                'kite_holdings' => $usableSnapshot['holdings'] ?? null,
                'local_open_orders' => $orders->map(fn (TradingOrder $order) => [
                    'order_id' => $order->id, 'broker_status' => $order->broker_status,
                    'quantity' => (float) $order->quantity, 'filled_quantity' => (float) $order->filled_quantity,
                    'side' => $order->side, 'last_synced_at' => $order->last_broker_sync_at?->toISOString(),
                ])->values()->all(),
                'kite_positions' => $usableSnapshot['positions'] ?? null,
            ];
            $row = KiteSessionEvidence::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'session_date' => $sessionDate, 'broker_account_id' => (string) $connection->broker_user_id],
                [
                    'user_id' => $user->id, 'broker_account_id' => (string) $connection->broker_user_id,
                    'provider' => 'kite', 'session_status' => $fresh ? 'incomplete' : 'unknown',
                    'ledger_status' => $ledgerCash === null ? 'unknown' : 'incomplete',
                    'buying_power_status' => isset($usableSnapshot['current_cash']) ? 'incomplete' : 'unknown',
                    'holdings_status' => isset($usableSnapshot['holdings']) ? 'incomplete' : 'unknown',
                    'orders_status' => $orders->isEmpty() ? 'incomplete' : 'incomplete',
                    'trades_status' => 'unknown', 'evidence' => $evidence,
                    'source_timestamps' => ['ledger' => $now->toISOString(), 'buying_power' => $captured?->toISOString(), 'holdings' => $captured?->toISOString(), 'local_orders' => $now->toISOString()],
                    'blocking_reasons' => $reasons, 'captured_at' => $now,
                ],
            );
            $results[] = $row;
        }
        return ['status' => $snapshot && $fresh ? 'incomplete' : 'unknown', 'records' => $results];
    }

    public function latest(User $user): array
    {
        $connection = BrokerConnection::query()->where('user_id', $user->id)->where('provider', 'kite')->first();
        if (! $connection || ! $connection->isUsable() || ! $connection->broker_user_id) {
            return [];
        }

        $liveProfileIds = PortfolioProfile::query()->where('user_id', $user->id)
            ->where('portfolio_type', PortfolioProfile::TYPE_LIVE)->pluck('id');

        return KiteSessionEvidence::query()->where('user_id', $user->id)->whereIn('profile_id', $liveProfileIds)
            ->where('broker_account_id', (string) $connection->broker_user_id)
            ->whereDate('session_date', now()->timezone('Asia/Kolkata')->toDateString())
            ->orderByDesc('captured_at')->get()->map(fn (KiteSessionEvidence $row) => [
                'profile_id' => $row->profile_id, 'session_date' => $row->session_date->toDateString(),
                'broker_account_id' => $row->broker_account_id, 'status' => $row->session_status,
                'source_status' => ['ledger' => $row->ledger_status, 'buying_power' => $row->buying_power_status,
                    'holdings' => $row->holdings_status, 'orders' => $row->orders_status, 'trades' => $row->trades_status],
                'evidence' => $row->evidence, 'source_timestamps' => $row->source_timestamps,
                'blocking_reasons' => $row->blocking_reasons, 'captured_at' => $row->captured_at->toISOString(),
            ])->all();
    }
}
