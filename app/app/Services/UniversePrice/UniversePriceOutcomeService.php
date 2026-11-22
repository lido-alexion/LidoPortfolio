<?php

namespace App\Services\UniversePrice;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\IgnoredPriceGap;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class UniversePriceOutcomeService
{
    private const DEFAULT_SKIP_AFTER = 5;
    private const DEFAULT_REPROBE_AFTER = 5;
    private const RETENTION_DAYS = 120;

    /** Persist one idempotent source outcome per stock and expected exchange session. */
    public function recordRun(array $stats, iterable $stocks, string $runId): array
    {
        $bulk = $stats['bulk'] ?? [];
        $expected = $bulk['expected_files'] ?? [];
        if (! is_array($expected) || $expected === []) return $this->summary();

        $stockList = collect($stocks)->values();
        $ids = $stockList->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) return $this->summary();
        $dates = array_values(array_unique(array_column($expected, 'date')));
        sort($dates);
        $ignoredGaps = collect();
        $covered = [];
        $coveredSource = [];
        $existingOutcomes = [];
        $states = [];
        foreach (array_chunk($ids, 500) as $idChunk) {
            $ignoredGaps = $ignoredGaps->concat(IgnoredPriceGap::query()->whereIn('stock_id', $idChunk)
                ->whereDate('gap_from', '<=', end($dates))->whereDate('gap_to', '>=', reset($dates))
                ->get(['stock_id', 'gap_from', 'gap_to']));
            $priceQuery = StockPrice::query()->whereIn('stock_id', $idChunk)
                ->whereDate('price_date', '>=', reset($dates))
                ->whereDate('price_date', '<=', end($dates));
            foreach ($priceQuery->get(['stock_id', 'price_date', 'provider_source', 'data_source']) as $price) {
                // Preserve the DATE column's stored session date. Converting
                // midnight UTC through the app timezone can shift it to tomorrow.
                $sessionDate = substr((string) $price->getRawOriginal('price_date'), 0, 10);
                $covered[(int) $price->stock_id][$sessionDate] = true;
                $coveredSource[(int) $price->stock_id][$sessionDate] = $price->provider_source ?: $price->data_source;
            }
            foreach (DB::table('portfolio_universe_price_session_outcomes')->whereIn('stock_id', $idChunk)
                ->whereBetween('session_date', [reset($dates), end($dates)])->get() as $row) {
                $existingOutcomes[(int) $row->stock_id][$row->session_date] = $row;
            }
            foreach (DB::table('portfolio_universe_price_symbol_states')->whereIn('stock_id', $idChunk)->get() as $row) {
                $states[(int) $row->stock_id] = $row;
            }
        }
        $ignoredGaps = $ignoredGaps->groupBy('stock_id');

        $files = [];
        foreach (($bulk['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['exchange'], $file['date'])) {
                $files[$file['exchange'].':'.$file['date']] = $file;
            }
        }

        $counts = ['covered' => 0, 'no_data' => 0, 'skipped' => 0, 'not_expected' => 0, 'provider_error' => 0, 'systemic_error' => 0, 'ignored' => 0];
        $outcomeRows = [];
        $stateRows = [];
        foreach ($stockList as $stock) {
            $state = $states[(int) $stock->id] ?? null;
            $consecutive = (int) ($state->consecutive_no_data_sessions ?? 0);
            $lastDate = $state->last_session_date ?? null;
            $lastOutcome = $state->last_outcome ?? null;
            $skippedUntil = $state->skipped_until_session ?? null;

            foreach ($dates as $date) {
                $key = $stock->exchange.':'.$date;
                $file = $files[$key] ?? null;
                $existing = $existingOutcomes[(int) $stock->id][$date] ?? null;
                $outcome = 'systemic_error';
                $reason = 'missing_expected_file_evidence';
                $hasPrice = isset($covered[(int) $stock->id][$date]);
                $failureStage = null;
                $attempt = (int) ($existing->attempt_count ?? 0);
                $retryAfter = null;
                $isIgnored = $ignoredGaps->get($stock->id, collect())->contains(fn ($gap) =>
                    Carbon::parse($gap->gap_from)->toDateString() <= $date
                    && Carbon::parse($gap->gap_to)->toDateString() >= $date);
                // created_at is an instant stored in UTC; ledger dates are India
                // exchange session dates, so compare in the market timezone.
                $stockKnownFrom = $stock->created_at
                    ? Carbon::parse((string) $stock->getRawOriginal('created_at'), 'UTC')
                        ->timezone('Asia/Kolkata')->toDateString()
                    : null;
                if (! $hasPrice && $stockKnownFrom !== null && $date < $stockKnownFrom) {
                    $outcome = 'not_expected';
                    $reason = 'before_stock_master_record';
                } elseif (is_array($file) && in_array($file['status'] ?? null, ['failed', 'retry_deferred'], true)) {
                    $failureStage = (string) ($file['failure_stage'] ?? 'exchange_fetch');
                    $outcome = $failureStage === 'exchange_fetch' ? 'provider_error' : 'systemic_error';
                    $reason = $failureStage;
                    $attempt = max($attempt, (int) ($file['retry_attempt'] ?? 0));
                    [$retryAfter, $validRetryAfter] = $this->sqlRetryTimestamp($file['retry_after'] ?? null);
                    if (! $validRetryAfter) {
                        $outcome = 'systemic_error';
                        $failureStage = 'receipt_validation';
                        $reason = 'invalid_retry_after';
                    }
                } elseif (is_array($file) && ($file['status'] ?? null) === 'identity_conflict' && ! $hasPrice) {
                    $outcome = 'systemic_error';
                    $failureStage = 'identity_validation';
                    $reason = 'identity_conflict';
                    $attempt = max($attempt, (int) ($file['retry_attempt'] ?? 1));
                    [$retryAfter, $validRetryAfter] = $this->sqlRetryTimestamp($file['retry_after'] ?? null);
                    if (! $validRetryAfter) {
                        $failureStage = 'receipt_validation';
                        $reason = 'invalid_retry_after';
                    }
                } elseif ($hasPrice) {
                    $outcome = 'covered';
                    $reason = null;
                } elseif ($isIgnored) {
                    $outcome = 'ignored';
                    $reason = 'admin_ignored_gap';
                } elseif (is_array($file) && in_array($file['status'] ?? null, ['applied', 'receipt_cached'], true)
                    && isset($file['coverage_hash'], $file['source_revision_sha256'])) {
                    $outcome = 'no_data';
                    $reason = 'absent_from_validated_exchange_file';
                }

                // Replaying an already observed date never advances the miss streak.
                $isNewSession = $existing === null;
                // Historical backfills/replays may refresh their idempotent outcome row,
                // but must never rewrite the forward-looking symbol state.
                $mayMutateState = $lastDate === null || $date >= (string) $lastDate;
                if ($outcome === 'no_data' && $isNewSession && $skippedUntil !== null && $date < $skippedUntil) {
                    $outcome = 'skipped';
                    $reason = 'temporary_no_data_reprobe_cooldown';
                }
                if ($outcome === 'no_data') {
                    if ($mayMutateState && $isNewSession && $skippedUntil !== null && $date >= $skippedUntil) $skippedUntil = null;
                    if ($mayMutateState && $isNewSession) {
                        $previousIsAdjacent = $lastDate !== null
                            && TradingCalendar::addSessions(Carbon::parse($lastDate), 1)->toDateString() === $date;
                        $consecutive = $previousIsAdjacent && $lastOutcome === 'no_data' ? $consecutive + 1 : 1;
                    }
                    $threshold = max(1, (int) config('portfolio.universe_price_sync.no_data_skip_after_sessions', self::DEFAULT_SKIP_AFTER));
                    if ($mayMutateState && $consecutive >= $threshold && ($skippedUntil === null || $date >= $skippedUntil)) {
                        $skipSessions = max(1, (int) config('portfolio.universe_price_sync.no_data_reprobe_after_sessions', self::DEFAULT_REPROBE_AFTER));
                        // skipped_until_session is the first eligible reprobe
                        // session; advance past all configured skipped sessions.
                        $skippedUntil = TradingCalendar::addSessions(Carbon::parse($date), $skipSessions + 1)->toDateString();
                    }
                } elseif ($outcome === 'covered' && $mayMutateState) {
                    $consecutive = 0;
                    $skippedUntil = null;
                } elseif ($outcome === 'skipped' || ! $mayMutateState) {
                    // The shared file is still checked; only this security's
                    // repeated no-data observation is held during cooldown.
                } else {
                    $consecutive = 0;
                    $skippedUntil = null;
                }

                $outcomeRows[] = [
                    'stock_id' => $stock->id, 'session_date' => $date,
                    'exchange' => $stock->exchange, 'outcome' => $outcome, 'price_present' => $hasPrice, 'reason' => $reason,
                    'failure_stage' => $failureStage,
                    'error_summary' => isset($file['error']) ? substr((string) $file['error'], 0, 500) : null,
                    'attempt_count' => $attempt, 'retry_after' => $retryAfter,
                    'source_revision_sha256' => $file['source_revision_sha256'] ?? null,
                    'resolved_by_source' => $outcome === 'covered'
                        ? ($coveredSource[(int) $stock->id][$date] ?? null)
                        : null,
                    'run_id' => $runId, 'observed_at' => now(), 'updated_at' => now(),
                    'created_at' => $existing->created_at ?? now(),
                ];
                $counts[$outcome]++;
                if ($mayMutateState) {
                    $lastDate = $date;
                    $lastOutcome = $outcome;
                }
            }

            $stateRows[] = [
                'stock_id' => $stock->id, 'consecutive_no_data_sessions' => $consecutive,
                'last_session_date' => $lastDate, 'last_outcome' => $lastOutcome,
                'skipped_until_session' => $skippedUntil, 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($outcomeRows, 500) as $chunk) {
            DB::table('portfolio_universe_price_session_outcomes')->upsert($chunk, ['stock_id', 'session_date'], [
                'exchange', 'outcome', 'price_present', 'reason', 'failure_stage', 'error_summary', 'attempt_count', 'retry_after',
                'source_revision_sha256', 'resolved_by_source', 'run_id', 'observed_at', 'updated_at',
            ]);
        }
        foreach (array_chunk($stateRows, 500) as $chunk) {
            DB::table('portfolio_universe_price_symbol_states')->upsert($chunk, ['stock_id'], [
                'consecutive_no_data_sessions', 'last_session_date', 'last_outcome', 'skipped_until_session', 'updated_at',
            ]);
        }

        DB::table('portfolio_universe_price_session_outcomes')
            ->where('session_date', '<', now('Asia/Kolkata')->subDays(self::RETENTION_DAYS)->toDateString())->delete();

        return $this->summary() + ['last_run' => $counts];
    }

    public function summary(): array
    {
        $cutoff = TradingCalendar::addSessions(now('Asia/Kolkata')->subDays(self::RETENTION_DAYS), 0)->toDateString();
        $rows = DB::table('portfolio_universe_price_session_outcomes')
            ->where('session_date', '>=', $cutoff)
            ->select('outcome', DB::raw('COUNT(*) as total'))
            ->groupBy('outcome')->pluck('total', 'outcome');
        $skipped = DB::table('portfolio_universe_price_symbol_states as state')
            ->join('portfolio_stocks as stock', 'stock.id', '=', 'state.stock_id')
            ->whereNotNull('state.skipped_until_session')
            ->where('state.skipped_until_session', '>', now('Asia/Kolkata')->toDateString())
            ->orderBy('stock.symbol')
            ->limit(250)
            ->get(['stock.id as stock_id', 'stock.symbol', 'stock.exchange', 'state.consecutive_no_data_sessions', 'state.last_session_date', 'state.skipped_until_session']);
        $recentSessions = DB::table('portfolio_universe_price_session_outcomes')
            ->where('session_date', '>=', $cutoff)
            ->whereIn('outcome', ['covered', 'no_data', 'provider_error', 'systemic_error', 'ignored', 'skipped', 'not_expected'])
            ->select('session_date', 'exchange', 'outcome', DB::raw('COUNT(*) as total'))
            ->groupBy('session_date', 'exchange', 'outcome')
            ->orderByDesc('session_date')->limit(120)->get();
        $adminEvents = DB::table('portfolio_universe_price_admin_events as event')
            ->join('portfolio_stocks as stock', 'stock.id', '=', 'event.stock_id')
            ->orderByDesc('event.id')->limit(20)
            ->get(['stock.symbol', 'stock.exchange', 'event.actor_id', 'event.action', 'event.session_date', 'event.details', 'event.created_at']);
        $recentErrors = DB::table('portfolio_universe_price_session_outcomes as outcome')
            ->join('portfolio_stocks as stock', 'stock.id', '=', 'outcome.stock_id')
            ->whereIn('outcome.outcome', ['provider_error', 'systemic_error'])
            ->orderByDesc('outcome.session_date')->orderByDesc('outcome.id')->limit(30)
            ->get(['stock.symbol', 'stock.exchange', 'outcome.session_date', 'outcome.outcome', 'outcome.reason',
                'outcome.failure_stage', 'outcome.error_summary', 'outcome.attempt_count', 'outcome.retry_after']);

        return [
            'window_days' => self::RETENTION_DAYS,
            'outcomes' => [
                'covered' => (int) ($rows['covered'] ?? 0),
                'no_data' => (int) ($rows['no_data'] ?? 0),
                'provider_error' => (int) ($rows['provider_error'] ?? 0),
                'systemic_error' => (int) ($rows['systemic_error'] ?? 0),
                'ignored' => (int) ($rows['ignored'] ?? 0),
                'skipped' => (int) ($rows['skipped'] ?? 0),
                'not_expected' => (int) ($rows['not_expected'] ?? 0),
            ],
            'temporary_skips' => $skipped,
            'recent_sessions' => $recentSessions,
            'recent_errors' => $recentErrors,
            'recent_admin_events' => $adminEvents,
            'skip_after_sessions' => max(1, (int) config('portfolio.universe_price_sync.no_data_skip_after_sessions', self::DEFAULT_SKIP_AFTER)),
            'reprobe_after_sessions' => max(1, (int) config('portfolio.universe_price_sync.no_data_reprobe_after_sessions', self::DEFAULT_REPROBE_AFTER)),
        ];
    }

    /** Normalize legacy ISO offsets to the actual UTC instant before SQL DATETIME persistence. */
    private function sqlRetryTimestamp(mixed $value): array
    {
        if ($value === null || $value === '') return [null, true];
        if (! is_string($value) || preg_match(
            '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/',
            $value,
        ) !== 1) return [null, false];

        try {
            return [Carbon::parse($value)->utc()->format('Y-m-d H:i:s'), true];
        } catch (\Throwable) {
            return [null, false];
        }
    }

    /** An actionable collapse requires two consecutive sessions after a stable baseline. */
    public function systemicCoverageCollapse(): ?array
    {
        foreach (['NSE', 'BSE'] as $exchange) {
            $byDate = DB::table('portfolio_universe_price_session_outcomes')
                ->where('exchange', $exchange)
                ->whereIn('outcome', ['covered', 'no_data', 'skipped'])
                ->select('session_date', 'outcome', DB::raw('COUNT(*) as total'))
                ->groupBy('session_date', 'outcome')
                ->orderByDesc('session_date')->get()->groupBy('session_date');
            $sessions = $byDate->keys()->sortDesc()->values();
            if ($sessions->count() < 7) continue;
            $ratioFor = function (string $date) use ($byDate): ?array {
                $rows = $byDate->get($date, collect())->keyBy('outcome');
                $covered = (int) ($rows->get('covered')->total ?? 0);
                $noData = (int) ($rows->get('no_data')->total ?? 0) + (int) ($rows->get('skipped')->total ?? 0);
                $observed = $covered + $noData;
                return $observed > 0 ? ['observed' => $observed, 'no_data' => $noData, 'ratio' => $noData / $observed] : null;
            };
            $recent = $sessions->take(4)->map(fn ($date) => $ratioFor((string) $date))->values();
            $baseline = collect($sessions->slice(2, 10)->map(fn ($date) => $ratioFor((string) $date)))
                ->filter(fn ($item) => $item !== null && $item['observed'] >= 3)->values();
            $healthyStreak = 0;
            foreach ($recent as $index => $item) {
                $adjacentToPrior = $index === 0 || TradingCalendar::addSessions(
                    Carbon::parse((string) $sessions[$index]), 1,
                )->toDateString() === (string) $sessions[$index - 1];
                if ($adjacentToPrior && $item !== null && $item['observed'] >= 3 && $item['ratio'] < 0.8) $healthyStreak++;
                else break;
            }
            $collapsePair = false;
            for ($i = 0; $i < min(3, $recent->count() - 1); $i++) {
                $adjacentPair = TradingCalendar::addSessions(Carbon::parse((string) $sessions[$i + 1]), 1)
                    ->toDateString() === (string) $sessions[$i];
                if ($adjacentPair && $recent[$i] !== null && $recent[$i + 1] !== null
                    && $recent[$i]['observed'] >= 3 && $recent[$i + 1]['observed'] >= 3
                    && $recent[$i]['ratio'] >= 0.8 && $recent[$i + 1]['ratio'] >= 0.8) {
                    $collapsePair = true;
                    break;
                }
            }
            if ($healthyStreak < 2 && $collapsePair && $baseline->count() >= 5 && $baseline->avg('ratio') <= 0.4) {
                return [
                    'exchange' => $exchange,
                    'sessions' => [(string) $sessions[1], (string) $sessions[0]],
                    'no_data_share_percent' => (int) round(100 * max($recent[0]['ratio'] ?? 0, $recent[1]['ratio'] ?? 0)),
                    'baseline_no_data_share_percent' => (int) round(100 * $baseline->avg('ratio')),
                ];
            }
        }
        return null;
    }

    public function resetForReprobe(Stock $stock, ?int $actorId): bool
    {
        $lock = Cache::store('database')->lock('portfolio-universe-price-sync', 60);
        if (! $lock->get()) return false;
        try {
            DB::transaction(function () use ($stock, $actorId): void {
                $state = DB::table('portfolio_universe_price_symbol_states')->where('stock_id', $stock->id)->first();
                DB::table('portfolio_universe_price_admin_events')->insert([
                    'stock_id' => $stock->id,
                    'actor_id' => $actorId,
                    'action' => 'manual_reprobe',
                    'session_date' => now('Asia/Kolkata')->toDateString(),
                    'details' => json_encode([
                        'previous_consecutive_no_data_sessions' => (int) ($state->consecutive_no_data_sessions ?? 0),
                        'previous_skipped_until_session' => $state->skipped_until_session ?? null,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                DB::table('portfolio_universe_price_symbol_states')->updateOrInsert(
                    ['stock_id' => $stock->id],
                    ['consecutive_no_data_sessions' => 0, 'last_outcome' => null, 'skipped_until_session' => null,
                        'updated_at' => now()],
                );
            });
            return true;
        } finally {
            $lock->release();
        }
    }

    /** A verified historical row repairs the observed session without erasing its source gap evidence. */
    public function markRecoveredByHistory(Stock $stock, array $sessionDates): void
    {
        $dates = collect($sessionDates)->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->values();
        if ($dates->isEmpty()) return;
        $persisted = [];
        $sessionDates = array_fill_keys($dates->all(), true);
        foreach (StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '>=', $dates->min())->whereDate('price_date', '<=', $dates->max())
            ->get(['price_date', 'provider_source', 'data_source']) as $price) {
            $date = substr((string) $price->getRawOriginal('price_date'), 0, 10);
            if (! isset($sessionDates[$date])) continue;
            $persisted[$date] = $price->provider_source ?: $price->data_source;
        }
        if ($persisted === []) return;
        foreach ($persisted as $date => $source) {
            DB::table('portfolio_universe_price_session_outcomes')
                ->where('stock_id', $stock->id)->where('session_date', $date)
                ->whereIn('outcome', ['no_data', 'provider_error', 'systemic_error'])
                ->update([
                    'outcome' => 'covered', 'price_present' => true, 'reason' => 'recovered_by_historical_backfill',
                    'resolved_by_source' => $source, 'updated_at' => now(),
                ]);
        }
        $state = DB::table('portfolio_universe_price_symbol_states')->where('stock_id', $stock->id)->first();
        if ($state && array_key_exists($state->last_session_date, $persisted)) {
            DB::table('portfolio_universe_price_symbol_states')->where('stock_id', $stock->id)->update([
                'consecutive_no_data_sessions' => 0, 'last_outcome' => 'covered',
                'skipped_until_session' => null, 'updated_at' => now(),
            ]);
        }
    }

}
