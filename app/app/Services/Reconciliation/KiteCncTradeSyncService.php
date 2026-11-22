<?php

namespace App\Services\Reconciliation;

use App\Models\KiteCncTradeImport;
use App\Models\KiteCncTradeSyncRun;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use App\Services\Broker\BrokerGateway;
use App\Services\TransactionWriteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class KiteCncTradeSyncService
{
    public function __construct(private BrokerGateway $broker, private TransactionWriteService $writes) {}

    /** @return array{run:KiteCncTradeSyncRun,imported:int,matched:int,review:int} */
    public function sync(PortfolioProfile $profile, string $trigger = 'manual'): array
    {
        if ($profile->portfolio_type !== PortfolioProfile::TYPE_LIVE
            || ! in_array($profile->execution_mode, [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC], true)
            || $this->broker->provider() !== BrokerConnection::PROVIDER_KITE) {
            throw new \InvalidArgumentException('Kite CNC trade sync is available only for connected live Semi-Automatic or Automatic portfolios.');
        }
        $eligibleProfiles = PortfolioProfile::query()->where('user_id', $profile->user_id)
            ->where('portfolio_type', PortfolioProfile::TYPE_LIVE)
            ->whereIn('execution_mode', [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC])
            ->count();
        if ($eligibleProfiles > 1) {
            throw new \InvalidArgumentException('Kite CNC sync is paused because this Kite account is linked to multiple live portfolios; execution ownership needs review before importing.');
        }
        // The trades request and one financial write per fill can exceed a
        // minute for a busy account. Keep the profile lock for the scheduler's
        // full overlap window so a manual/reconnect trigger cannot race it.
        $lock = Cache::lock('kite-cnc-sync:'.$profile->id, 600);
        return $lock->block(2, fn () => $this->syncLocked($profile, $trigger));
    }

    private function syncLocked(PortfolioProfile $profile, string $trigger): array
    {
        $started = now();
        $run = KiteCncTradeSyncRun::query()->create([
            'profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'trading_date' => $started->copy()->timezone('Asia/Kolkata')->toDateString(),
            'trigger' => $trigger, 'status' => 'running', 'started_at' => $started,
        ]);
        try {
            $rows = $this->broker->trades((int) $profile->user_id);
            if ($rows === null) throw new \RuntimeException('Kite current-day trades are unavailable; sync remains incomplete.');
            $eligible = collect($rows)->filter(fn ($row) => is_array($row)
                && strtoupper((string) ($row['product'] ?? '')) === 'CNC'
                && in_array(strtoupper((string) ($row['exchange'] ?? '')), ['NSE', 'BSE'], true)
                && in_array(strtoupper((string) ($row['transaction_type'] ?? '')), ['BUY', 'SELL'], true)
                && is_numeric($row['trade_id'] ?? null)
                && is_numeric($row['quantity'] ?? null) && (float) $row['quantity'] > 0
                && is_numeric($row['price'] ?? null) && (float) $row['price'] > 0
                // Never substitute an order submission timestamp for execution
                // evidence: it can cross a session boundary or be absent.
                // Kite's tradebook contract exposes exchange_timestamp as the
                // execution time. Do not infer execution time from order or
                // undocumented fill timestamp fields.
                && ! empty($row['exchange_timestamp']))
                ->values();
            $cncCandidates = collect($rows)->filter(fn ($row) => is_array($row)
                && strtoupper((string) ($row['product'] ?? '')) === 'CNC'
                && in_array(strtoupper((string) ($row['exchange'] ?? '')), ['NSE', 'BSE'], true))->count();
            $prepared = $eligible->map(function (array $row): ?array {
                $symbol = strtoupper(trim((string) ($row['tradingsymbol'] ?? '')));
                $exchange = strtoupper((string) $row['exchange']);
                $stock = Stock::query()->where('symbol', $symbol)->where(function ($q) use ($exchange) {
                    $q->where('exchange', $exchange)->orWhere('is_dual_listed', true);
                })->first();
                if (! $stock) return null;
                $when = $row['exchange_timestamp'];
                return ['raw' => $row, 'stock' => $stock, 'symbol' => $symbol, 'exchange' => $exchange,
                    'date' => \Illuminate\Support\Carbon::parse($when, 'Asia/Kolkata')->timezone('Asia/Kolkata')->toDateString(),
                    'side' => strtolower((string) $row['transaction_type']), 'trade_id' => (string) $row['trade_id'],
                    'quantity' => (float) $row['quantity'], 'price' => (float) $row['price'], 'executed_at' => $when];
            })->filter(fn (?array $trade) => $trade !== null && $trade['date'] === $started->copy()->timezone('Asia/Kolkata')->toDateString())->values();

            $manualMatches = $this->manualMatches($profile, $prepared);
            $imported = $matched = 0;
            $review = max(0, $cncCandidates - $prepared->count());
            foreach ($prepared as $trade) {
                DB::transaction(function () use ($profile, $run, $trade, $manualMatches, &$imported, &$matched, &$review): void {
                    $prior = KiteCncTradeImport::query()->where('profile_id', $profile->id)->where('broker_trade_id', $trade['trade_id'])->first();
                    if ($prior) return;
                    $groupKey = $trade['stock']->id.'|'.$trade['date'].'|'.$trade['side'];
                    $manual = $manualMatches[$groupKey][$trade['trade_id']] ?? null;
                    $status = 'imported';
                    $transaction = null;
                    $matchedTransaction = null;
                    if (is_array($manual) && ($manual['kind'] ?? '') === 'match') {
                        $status = $manual['transaction']->source === Transaction::SOURCE_MANUAL ? 'matched_manual' : 'matched_existing';
                        $matchedTransaction = $manual['transaction'];
                        $matched++;
                    } elseif (is_array($manual)) {
                        $status = 'manual_match_review';
                        $review++;
                    } else {
                        try {
                            $transaction = $this->writes->create($profile, $trade['stock'], [
                                'type' => $trade['side'], 'quantity' => $trade['quantity'], 'price' => $trade['price'],
                                'execution_exchange' => $trade['exchange'], 'transaction_date' => $trade['date'],
                                'source' => Transaction::SOURCE_OTHER,
                                'notes' => 'Kite CNC execution '.$trade['trade_id'].' (order '.($trade['raw']['order_id'] ?? 'unknown').')',
                            ], user: null);
                            $imported++;
                        } catch (Throwable $error) {
                            // A post-commit snapshot side effect can fail after the
                            // ledger write succeeded. Detect that durable write so
                            // the broker fill is not mislabeled or imported twice.
                            $transaction = Transaction::query()->where('profile_id', $profile->id)
                                ->where('stock_id', $trade['stock']->id)->whereDate('transaction_date', $trade['date'])
                                ->where('notes', 'like', 'Kite CNC execution '.$trade['trade_id'].' (%')->first();
                            if ($transaction) {
                                $imported++;
                            } else {
                                // Attribution/validation failures are reviewed, never guessed.
                                $status = 'manual_match_review';
                                $review++;
                            }
                        }
                    }
                    KiteCncTradeImport::query()->create([
                        'profile_id' => $profile->id, 'sync_run_id' => $run->id,
                        'stock_id' => $trade['stock']->id,
                        'broker_trade_id' => $trade['trade_id'], 'broker_order_id' => isset($trade['raw']['order_id']) ? (string) $trade['raw']['order_id'] : null,
                        'transaction_id' => $transaction?->id, 'matched_transaction_id' => $matchedTransaction?->id,
                        'symbol' => $trade['symbol'], 'exchange' => $trade['exchange'], 'side' => $trade['side'],
                        'quantity' => $trade['quantity'], 'price' => $trade['price'], 'executed_at' => $trade['executed_at'],
                        'product' => 'CNC', 'status' => $status, 'broker_evidence' => $trade['raw'],
                    ]);
                });
            }
            $run->forceFill(['status' => $review > 0 ? 'needs_review' : 'completed', 'received_count' => count($rows),
                'eligible_count' => $eligible->count(), 'imported_count' => $imported, 'matched_count' => $matched,
                'review_count' => $review, 'completed_at' => now()])->save();
            $this->recordUnavailableTradingDayGaps($profile, $run->trading_date->toDateString());
            return compact('run', 'imported', 'matched', 'review');
        } catch (Throwable $error) {
            $run->forceFill(['status' => 'sync_failed', 'failure' => mb_substr($error->getMessage(), 0, 1000), 'completed_at' => now()])->save();
            throw $error;
        }
    }

    private function recordUnavailableTradingDayGaps(PortfolioProfile $profile, string $today): void
    {
        $last = KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)
            ->whereDate('trading_date', '<', $today)->whereIn('status', ['completed', 'needs_review'])
            ->orderByDesc('trading_date')->first();
        if (! $last) return; // First connection has no known missing interval to report.
        $cursor = \Illuminate\Support\Carbon::parse($last->trading_date->toDateString(), 'Asia/Kolkata')->addDay();
        $end = \Illuminate\Support\Carbon::parse($today, 'Asia/Kolkata')->subDay();
        while ($cursor->lte($end)) {
            if (\App\Support\TradingCalendar::isEquitySessionDate($cursor)
                && ! KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)->whereDate('trading_date', $cursor->toDateString())
                    ->whereIn('status', ['completed', 'needs_review', 'gap_unavailable'])->exists()) {
                KiteCncTradeSyncRun::query()->create([
                    'profile_id' => $profile->id, 'user_id' => $profile->user_id, 'trading_date' => $cursor->toDateString(),
                    'trigger' => 'gap_detection', 'status' => 'gap_unavailable',
                    'failure' => 'Kite Trades API exposes only the current trading day; this missed session cannot be retrieved here.',
                    'started_at' => now(), 'completed_at' => now(),
                ]);
            }
            $cursor->addDay();
        }
    }

    /** @param \Illuminate\Support\Collection<int,array<string,mixed>> $trades @return array<string,array<string,array<string,mixed>>> */
    private function manualMatches(PortfolioProfile $profile, $trades): array
    {
        $keys = $trades->map(fn ($t) => $t['stock']->id.'|'.$t['date'].'|'.$t['side'])->unique();
        $result = [];
        foreach ($keys as $key) {
            [$stockId, $date, $side] = explode('|', $key);
            $rows = $trades->filter(fn ($t) => $t['stock']->id === (int) $stockId && $t['date'] === $date && $t['side'] === $side);
            $quantity = (float) $rows->sum('quantity');
            $turnover = (float) $rows->sum(fn ($t) => $t['quantity'] * $t['price']);
            $existing = Transaction::query()->where('profile_id', $profile->id)->where('stock_id', (int) $stockId)
                ->where('type', $side)->whereDate('transaction_date', $date)->get();
            if ($existing->isEmpty()) continue;

            // Prefer a one-to-one match when the operator entered each partial
            // fill separately. Identical fills are paired by stable row order
            // only when the complete candidate multiset has the same counts.
            $remaining = $existing->sortBy('id')->values();
            $matchesByTrade = [];
            $oneToOne = $existing->count() === $rows->count();
            foreach ($rows as $trade) {
                $matches = $remaining->filter(fn ($tx) => abs((float) $tx->quantity - (float) $trade['quantity']) < 0.0001
                    && abs((float) $tx->price - (float) $trade['price']) < 0.02)->values();
                $identicalFillCount = $rows->filter(fn ($candidate) => abs((float) $candidate['quantity'] - (float) $trade['quantity']) < 0.0001
                    && abs((float) $candidate['price'] - (float) $trade['price']) < 0.02)->count();
                if ($matches->count() > 1 && $matches->count() === $identicalFillCount) {
                    $matches = $matches->take(1)->values();
                }
                if ($matches->count() !== 1) {
                    $oneToOne = false;
                    break;
                }
                $match = $matches->first();
                $matchesByTrade[$trade['trade_id']] = $match;
                $remaining = $remaining->reject(fn ($tx) => (int) $tx->id === (int) $match->id)->values();
            }
            if ($oneToOne && $remaining->isEmpty()) {
                foreach ($matchesByTrade as $tradeId => $transaction) {
                    $result[$key][$tradeId] = ['kind' => 'match', 'transaction' => $transaction];
                }
                continue;
            }

            $aggregateMatches = $existing->filter(fn ($tx) => abs((float) $tx->quantity - $quantity) < 0.0001
                && abs((float) $tx->price - $turnover / max($quantity, 0.0001)) < 0.02);
            if ($aggregateMatches->count() === 1) {
                foreach ($rows as $trade) $result[$key][$trade['trade_id']] = ['kind' => 'match', 'transaction' => $aggregateMatches->first()];
            } else {
                foreach ($rows as $trade) $result[$key][$trade['trade_id']] = ['kind' => 'review'];
            }
        }
        return $result;
    }
}
