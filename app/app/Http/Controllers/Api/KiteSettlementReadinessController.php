<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Services\Reconciliation\KiteSettlementDiagnosticReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class KiteSettlementReadinessController extends Controller
{
    private const MAX_ROWS = 5000;

    public function show(Request $request, KiteSettlementDiagnosticReport $report): JsonResponse
    {
        $today = now('Asia/Kolkata')->toDateString();
        $input = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:'.now('Asia/Kolkata')->subYear()->toDateString()],
        ]);
        $profileId = $request->header('X-Profile-Id') ?? $request->header('X-Portfolio-Id') ?? $request->query('portfolio_id');
        $profileQuery = PortfolioProfile::query()->where('user_id', $request->user()->id);
        if ($profileId !== null && $profileId !== '') {
            abort_unless(is_scalar($profileId) && ctype_digit((string) $profileId), 404);
            $profileQuery->whereKey((int) $profileId);
        } else {
            $profileQuery->where('is_default', true);
        }
        $profile = $profileQuery->first();
        abort_unless($profile !== null, 404, 'Portfolio not found.');

        // Resolve the account only through the authenticated user's connection.
        // Never accept profile/account identifiers from request input.
        $connections = BrokerConnection::query()->where('user_id', $request->user()->id)
            ->where('provider', BrokerConnection::PROVIDER_KITE)->limit(2)->get();
        abort_unless($connections->count() === 1, 409, 'A single connected Kite account is required for settlement diagnostics.');
        $connection = $connections->first();
        abort_unless($connection->isUsable() && $connection->connected_at !== null
            && $connection->expires_at !== null && $connection->expires_at->isFuture(),
            409, 'The connected Kite session is unavailable, stale, or expired.');
        $accountId = $connection->broker_user_id;
        abort_unless(is_string($accountId) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $accountId) === 1, 409,
            'The connected Kite account identity is unavailable.');

        $day = $input['date'] ?? now('Asia/Kolkata')->toDateString();
        $istStart = new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone('Asia/Kolkata'));
        $utcStart = $istStart->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $utcEnd = $istStart->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $snapshots = DB::table('portfolio_portfolio_snapshots')->where('profile_id', $profile->id)
            ->where('snapshot_date', $day)->limit(self::MAX_ROWS + 1)->get()->map(fn ($row) => [
                'profile_id' => (int) $row->profile_id, 'snapshot_date' => (string) $row->snapshot_date,
            ])->all();
        $trades = DB::table('kite_cnc_trade_imports')->where('profile_id', $profile->id)
            ->where('executed_at', '>=', $utcStart)->where('executed_at', '<', $utcEnd)
            ->limit(self::MAX_ROWS + 1)->get()->map(fn ($row) => [
                'profile_id' => (int) $row->profile_id, 'broker_account_id' => $row->broker_account_id,
                'broker_trade_id' => $row->broker_trade_id, 'stock_id' => $row->stock_id,
                'symbol' => $row->symbol, 'exchange' => $row->exchange, 'side' => $row->side,
                'quantity' => $row->quantity, 'price' => $row->price, 'executed_at' => $this->asUtcTimestamp($row->executed_at),
                'product' => $row->product, 'status' => $row->status,
            ])->all();
        $runs = DB::table('kite_cnc_trade_sync_runs')->where('profile_id', $profile->id)->where('broker_account_id', $accountId)
            ->whereDate('trading_date', $day)->limit(self::MAX_ROWS + 1)->get()->map(fn ($row) => [
                'profile_id' => (int) $row->profile_id, 'broker_account_id' => $row->broker_account_id,
                'trading_date' => (string) $row->trading_date, 'status' => $row->status,
                'review_count' => $row->review_count, 'started_at' => $this->asUtcTimestamp($row->started_at),
                'completed_at' => $this->asUtcTimestamp($row->completed_at),
            ])->all();
        $funds = DB::table('portfolio_kite_funds_snapshots')->where('profile_id', $profile->id)
            ->where('broker_account_id', $accountId)->whereDate('trading_date', $day)->limit(self::MAX_ROWS + 1)->get()->map(fn ($row) => [
                'profile_id' => (int) $row->profile_id, 'broker_account_id' => $row->broker_account_id,
                'trading_date' => (string) $row->trading_date, 'captured_at' => $this->asUtcTimestamp($row->captured_at),
                'freshness' => $row->freshness, 'payout_semantics_verified' => (bool) $row->payout_semantics_verified,
            ])->all();

        foreach ([$snapshots, $trades, $runs, $funds] as $rows) {
            abort_if(count($rows) > self::MAX_ROWS, 422, 'Evidence volume exceeds the bounded diagnostic limit.');
        }

        // A usable user-level connection does not establish which profile it
        // belongs to. Only durable application records carrying both IDs do.
        $hasExplicitProfileAccountProvenance = DB::table('kite_cnc_trade_sync_runs')
            ->where('profile_id', $profile->id)->where('broker_account_id', $accountId)->exists()
            || DB::table('kite_cnc_trade_imports')->where('profile_id', $profile->id)->where('broker_account_id', $accountId)->exists()
            || DB::table('portfolio_kite_funds_snapshots')->where('profile_id', $profile->id)->where('broker_account_id', $accountId)->exists();

        $result = $report->build(
            profileId: (int) $profile->id,
            brokerAccountId: $accountId,
            tradingDay: $day,
            accountBinding: $hasExplicitProfileAccountProvenance
                ? ['profile_id' => (int) $profile->id, 'broker_account_id' => $accountId, 'verified' => true]
                : null,
            portfolioSnapshots: $snapshots,
            tradeImports: $trades,
            syncRuns: $runs,
            fundsSnapshots: $funds,
            // No durable, independently attributed holdings/positions capture exists.
            stoxHoldings: null,
            brokerSnapshot: null,
        );

        return response()->json(['data' => $result]);
    }

    private function asUtcTimestamp(?string $value): ?string
    {
        if ($value === null || preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/D', $value) === 1) {
            return $value;
        }

        return $value.'Z';
    }
}
