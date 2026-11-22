<?php

namespace App\Services\Broker;

use App\Models\BrokerConnection;
use App\Models\KiteFundsReconciliation;
use App\Models\KiteFundsSnapshot;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Support\TradingCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\QueryException;

final class KiteFundsSnapshotService
{
    public function capture(BrokerConnection $connection, string $source = 'periodic'): ?KiteFundsSnapshot
    {
        if (! $connection->isUsable() || ! $connection->broker_user_id) return null;
        $user = User::query()->find($connection->user_id);
        $profile = $user?->portfolios()->where('portfolio_type', PortfolioProfile::TYPE_LIVE)->where('is_default', true)->first()
            ?? $user?->portfolios()->where('portfolio_type', PortfolioProfile::TYPE_LIVE)->orderBy('id')->first();
        if (! $profile) return null;

        try {
            $response = Http::timeout(15)->withHeaders([
                'X-Kite-Version' => '3',
                'Authorization' => 'token '.config('broker.kite.api_key').':'.$connection->access_token,
            ])->get(rtrim((string) config('broker.kite.api_base'), '/').'/user/margins/equity');
        } catch (\Throwable) { return null; }
        $data = $response->successful() ? data_get($response->json(), 'data') : null;
        if (! is_array($data)) return null;

        $captured = now('Asia/Kolkata');
        $isSessionDate = TradingCalendar::isEquitySessionDate($captured);
        $available = is_array($data['available'] ?? null) ? $data['available'] : [];
        $utilised = is_array($data['utilised'] ?? null) ? $data['utilised'] : [];
        $fields = [
            'available_balance' => $available['live_balance'] ?? $data['net'] ?? null,
            'opening_balance' => $available['opening_balance'] ?? null,
            'intraday_payin' => $available['intraday_payin'] ?? null,
            'utilised_payout' => $utilised['payout'] ?? null,
        ];
        foreach ($fields as $key => $value) $fields[$key] = is_numeric($value) ? round((float) $value, 4) : null;
        $freshness = ! $isSessionDate ? 'off_session'
            : (collect($fields)->every(fn ($value) => $value !== null) ? 'complete' : 'partial');
        $minuteKey = $captured->format('YmdHi');
        $idempotencyKey = hash('sha256', implode('|', [$connection->broker_user_id, $profile->id, $minuteKey, $source]));

        try {
            return KiteFundsSnapshot::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'profile_id' => $profile->id, 'user_id' => $user->id,
            'broker_account_id' => $connection->broker_user_id,
            'trading_date' => TradingCalendar::normalizeToSessionDate($captured)->toDateString(), 'captured_at' => $captured->copy()->utc(),
            ...$fields, 'payout_semantics_verified' => (bool) config('broker.kite_funds.payout_semantics_verified', false),
            'capture_source' => $source, 'freshness' => $freshness,
            'provenance' => ['endpoint' => '/user/margins/equity', 'http_status' => $response->status(),
                'captured_at_utc' => $captured->copy()->utc()->toISOString()],
            ]);
        } catch (QueryException $error) {
            $existing = KiteFundsSnapshot::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $existing;
            throw $error;
        }
    }

    /** Captures a morning recheck for the latest completed NSE session. */
    public function reconcilePreviousSession(): int
    {
        $previousSession = TradingCalendar::normalizeToSessionDate(now('Asia/Kolkata')->subDay())->toDateString();
        $count = 0;
        $connections = BrokerConnection::query()->where('provider', BrokerConnection::PROVIDER_KITE)->get();
        foreach ($connections as $connection) {
            $user = User::query()->find($connection->user_id);
            $profile = $user?->portfolios()->where('portfolio_type', PortfolioProfile::TYPE_LIVE)->where('is_default', true)->first()
                ?? $user?->portfolios()->where('portfolio_type', PortfolioProfile::TYPE_LIVE)->orderBy('id')->first();
            if (! $profile || ! $connection->broker_user_id) continue;
            $openDates = KiteFundsReconciliation::query()->where('profile_id', $profile->id)
                ->where('broker_account_id', $connection->broker_user_id)
                ->whereDate('trading_date', '>=', now('Asia/Kolkata')->subDays(30)->toDateString())
                ->whereNotIn('status', ['adjustment_confirmed', 'adjustment_suppressed'])
                ->pluck('trading_date')->map(fn ($value) => \Illuminate\Support\Carbon::parse($value)->toDateString())->all();
            $dates = collect([$previousSession, ...$openDates])->unique()->values();
            foreach ($dates as $date) {
                // A profile may have snapshots from a previously connected Kite account.
                // Never reconcile one account using another account's evidence.
                $snapshots = KiteFundsSnapshot::query()->where('profile_id', $profile->id)
                    ->where('broker_account_id', $connection->broker_user_id)->whereDate('trading_date', $date);
                $snapshot = (clone $snapshots)->where('freshness', 'complete')->where('capture_source', 'late_day')
                    ->orderByDesc('captured_at')->first()
                    ?? (clone $snapshots)->orderByDesc('captured_at')->first();
                $manual = DB::table('portfolio_cash_ledger_entries as movement')->where('movement.profile_id', $profile->id)->whereDate('movement.entry_date', $date)
                    ->whereIn('movement.entry_type', ['deposit', 'withdrawal'])->whereNull('movement.reversal_of_entry_id')
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('portfolio_cash_ledger_entries as reversal')->whereColumn('reversal.reversal_of_entry_id', 'movement.id'))
                    ->get(['movement.entry_type', 'movement.amount']);
                // Ledger amounts already carry their sign (withdrawals are negative).
                // Let MySQL sum DECIMAL values to retain the ledger's 4-place precision.
                $manualNetRaw = DB::table('portfolio_cash_ledger_entries as movement')->where('movement.profile_id', $profile->id)->whereDate('movement.entry_date', $date)
                    ->whereIn('movement.entry_type', ['deposit', 'withdrawal'])->whereNull('movement.reversal_of_entry_id')
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('portfolio_cash_ledger_entries as reversal')->whereColumn('reversal.reversal_of_entry_id', 'movement.id'))
                    ->sum('movement.amount');
                $manualNetUnits = $this->decimalUnits((string) ($manualNetRaw ?? '0'));
                $manualNet = $this->decimalFromUnits($manualNetUnits);
                $key = ['profile_id' => $profile->id, 'broker_account_id' => $connection->broker_user_id, 'trading_date' => $date];
                $existing = KiteFundsReconciliation::query()->where('profile_id', $profile->id)
                    ->where('broker_account_id', $connection->broker_user_id)->whereDate('trading_date', $date)->first();
                $suppressionReason = $existing?->suppression_reason;
                if ($existing?->status === 'adjustment_proposed' && $manual->isNotEmpty()) $suppressionReason = 'manual_override';
                $complete = $snapshot && $snapshot->freshness === 'complete';
                $lateCapture = $snapshot?->captured_at?->timezone('Asia/Kolkata');
                $final = $complete && $snapshot->capture_source === 'late_day'
                    && $lateCapture?->hour === 23 && $lateCapture?->minute >= 30;
                $netUnits = $complete ? $this->decimalUnits((string) $snapshot->intraday_payin)
                    - $this->decimalUnits((string) $snapshot->utilised_payout) : null;
                $net = $netUnits !== null ? $this->decimalFromUnits($netUnits) : null;
                $hasPayoutEvidence = $final && $snapshot->payout_semantics_verified;
                $zeroNetMixedActivity = $complete && $netUnits === 0
                    && (float) $snapshot->intraday_payin > 0 && (float) $snapshot->utilised_payout > 0;
                $status = match (true) {
                    ! $snapshot => 'missing_snapshot',
                    ! $complete => 'incomplete_snapshot',
                    ! $final => 'stale_snapshot',
                    ! $hasPayoutEvidence => 'review_required',
                    $zeroNetMixedActivity => 'zero_net_review',
                    $manual->isNotEmpty() && $manualNetUnits === $netUnits => 'reconciled',
                    $manual->isNotEmpty() => 'mismatch_warning',
                    $suppressionReason !== null => 'adjustment_suppressed',
                    $netUnits === 0 => 'zero_net_review',
                    ! config('broker.kite_funds.auto_adjustments_enabled', false) => 'adjustment_disabled',
                    default => 'adjustment_proposed',
                };
                DB::transaction(function () use ($key, $user, $snapshot, $status, $net, $manual, $manualNet, $suppressionReason) {
                    $current = KiteFundsReconciliation::query()->where('profile_id', $key['profile_id'])
                        ->where('broker_account_id', $key['broker_account_id'])->whereDate('trading_date', $key['trading_date'])
                        ->lockForUpdate()->first();
                    $terminal = ['adjustment_confirmed', 'adjustment_suppressed'];
                    $effectiveStatus = $current && in_array($current->status, $terminal, true) ? $current->status : $status;
                    $effectiveSuppression = $current?->status === 'adjustment_proposed' && $manual->isNotEmpty()
                        ? 'manual_override' : ($current?->suppression_reason ?? $suppressionReason);
                    $values = [
                        'user_id' => $user->id, 'snapshot_id' => $snapshot?->id,
                        'status' => $effectiveStatus,
                        'snapshot_net_flow' => $net, 'manual_net_flow' => $manual->isNotEmpty() ? $manualNet : null,
                        'suppression_reason' => $effectiveSuppression,
                        'evaluated_at' => now(),
                    ];
                    if ($current) {
                        $current->forceFill($values)->save();
                    } else {
                        try {
                            KiteFundsReconciliation::query()->create([...$key, ...$values]);
                        } catch (QueryException $error) {
                            // The unique account/date constraint arbitrates concurrent first reviews.
                            // Adopt the winner and retain any terminal decision it recorded.
                            $winner = KiteFundsReconciliation::query()->where('profile_id', $key['profile_id'])
                                ->where('broker_account_id', $key['broker_account_id'])->whereDate('trading_date', $key['trading_date'])
                                ->lockForUpdate()->first();
                            if (! $winner) throw $error;
                            if (in_array($winner->status, $terminal, true)) {
                                $values['status'] = $winner->status;
                                $values['cash_ledger_entry_id'] = $winner->cash_ledger_entry_id;
                            }
                            $winner->forceFill($values)->save();
                        }
                    }
                });
                $count++;
            }
        }
        return $count;
    }

    private function decimalUnits(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $units = ((int) $whole * 10000) + (int) str_pad(substr($fraction, 0, 4), 4, '0');
        return $negative ? -$units : $units;
    }

    private function decimalFromUnits(int $units): string
    {
        return ($units < 0 ? '-' : '').intdiv(abs($units), 10000).'.'.str_pad((string) (abs($units) % 10000), 4, '0', STR_PAD_LEFT);
    }
}
