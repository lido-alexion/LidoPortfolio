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
    private const MAX_SOURCE_ROWS = 5000;
    private const MAX_DUPLICATE_VARIANTS = 8;

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
        $connection = BrokerConnection::query()->where('user_id', $profile->user_id)
            ->where('provider', BrokerConnection::PROVIDER_KITE)->first();
        $brokerAccountId = trim((string) ($connection?->broker_user_id ?? ''));
        if ($brokerAccountId === '') {
            throw new \InvalidArgumentException('Kite CNC sync is paused because the connected Kite account identity is unavailable. Reconnect Kite before syncing.');
        }
        if (KiteCncTradeImport::query()->where('profile_id', $profile->id)->where('broker_account_id', '!=', $brokerAccountId)->exists()) {
            throw new \InvalidArgumentException('Kite CNC sync is paused because this portfolio contains executions from a different Kite account.');
        }
        // The trades request and one financial write per fill can exceed a
        // minute for a busy account. Keep the profile lock for the scheduler's
        // full overlap window so a manual/reconnect trigger cannot race it.
        $lock = Cache::lock('kite-cnc-sync:'.$profile->id, 600);
        return $lock->block(2, fn () => $this->syncLocked($profile, $trigger, $brokerAccountId));
    }

    private function syncLocked(PortfolioProfile $profile, string $trigger, string $brokerAccountId): array
    {
        $started = now();
        $run = KiteCncTradeSyncRun::query()->create([
            'profile_id' => $profile->id, 'user_id' => $profile->user_id, 'broker_account_id' => $brokerAccountId,
            'trading_date' => $started->copy()->timezone('Asia/Kolkata')->toDateString(),
            'trigger' => $trigger, 'status' => 'running', 'started_at' => $started,
        ]);
        try {
            $rows = DB::transaction(function () use ($profile, $brokerAccountId): ?array {
                $connection = BrokerConnection::query()->where('user_id', $profile->user_id)
                    ->where('provider', BrokerConnection::PROVIDER_KITE)->lockForUpdate()->first();
                $currentAccountId = trim((string) ($connection?->broker_user_id ?? ''));
                if ($currentAccountId === '' || ! hash_equals($brokerAccountId, $currentAccountId)) {
                    throw new \RuntimeException('The connected Kite account changed during CNC sync preparation; retry after checking the active account.');
                }
                return $this->broker->trades((int) $profile->user_id);
            });
            if ($rows === null) throw new \RuntimeException('Kite current-day trades are unavailable; sync remains incomplete.');
            if (count($rows) > self::MAX_SOURCE_ROWS) {
                throw new \RuntimeException('Kite trade source exceeded the bounded row limit; no rows were processed.');
            }
            $this->assertProviderRowsBounded($rows);
            $cncRows = collect($rows)->filter(fn ($row) => is_array($row) && $this->isCncCandidate($row))->values();
            $duplicateTradeIds = $cncRows->filter(fn ($row) => is_numeric($row['trade_id'] ?? null))
                ->groupBy(fn ($row) => (string) $row['trade_id'])->filter(fn ($group) => $group->count() > 1)
                ->keys()->map(fn ($tradeId) => (string) $tradeId)->all();
            $eligible = collect($rows)->filter(fn ($row) => is_array($row)
                && is_string($row['product'] ?? null) && strtoupper($row['product']) === 'CNC'
                && is_string($row['exchange'] ?? null) && in_array(strtoupper($row['exchange']), ['NSE', 'BSE'], true)
                && is_string($row['transaction_type'] ?? null) && in_array(strtoupper($row['transaction_type']), ['BUY', 'SELL'], true)
                && is_scalar($row['trade_id'] ?? null) && ctype_digit((string) $row['trade_id']) && strlen((string) $row['trade_id']) <= 64
                && $this->positiveDecimal($row['quantity'] ?? null) !== null
                && $this->positiveDecimal($row['price'] ?? null) !== null
                && ! in_array((string) ($row['trade_id'] ?? ''), $duplicateTradeIds, true)
                // Never substitute an order submission timestamp for execution
                // evidence: it can cross a session boundary or be absent.
                // Kite's tradebook contract exposes exchange_timestamp as the
                // execution time. Do not infer execution time from order or
                // undocumented fill timestamp fields.
                && $this->parseExecutionTime($row['exchange_timestamp'] ?? null) !== null)
                ->values();
            $prepared = $eligible->map(function (array $row): ?array {
                $rawSymbol = $row['tradingsymbol'] ?? null;
                $symbol = is_string($rawSymbol) ? strtoupper(trim($rawSymbol)) : '';
                $exchange = strtoupper((string) $row['exchange']);
                $stock = Stock::query()->where('symbol', $symbol)->where(function ($q) use ($exchange) {
                    $q->where('exchange', $exchange)->orWhere('is_dual_listed', true);
                })->first();
                if (! $stock) return null;
                $executedAt = $this->parseExecutionTime($row['exchange_timestamp'] ?? null);
                if (! $executedAt) return null;
                return ['raw' => $row, 'stock' => $stock, 'symbol' => $symbol, 'exchange' => $exchange,
                    'date' => $executedAt->toDateString(),
                    'side' => strtolower((string) $row['transaction_type']), 'trade_id' => (string) $row['trade_id'],
                    'quantity' => $this->positiveDecimal($row['quantity']), 'price' => $this->positiveDecimal($row['price']), 'executed_at' => $executedAt];
            })->filter(fn (?array $trade) => $trade !== null && $trade['date'] === $started->copy()->timezone('Asia/Kolkata')->toDateString())->values();

            $manualMatches = $this->manualMatches($profile, $prepared);
            $imported = $matched = 0;
            $review = 0;
            $validTradeIds = $prepared->pluck('trade_id')->all();
            $seenCandidateIds = [];
            foreach ($cncRows as $candidate) {
                $candidateId = is_numeric($candidate['trade_id'] ?? null) ? (string) $candidate['trade_id'] : null;
                if ($candidateId !== null && in_array($candidateId, $validTradeIds, true)) continue;
                if ($candidateId !== null && in_array($candidateId, $duplicateTradeIds, true) && isset($seenCandidateIds[$candidateId])) continue;
                if ($candidateId !== null && in_array($candidateId, $duplicateTradeIds, true)) $seenCandidateIds[$candidateId] = true;
                $safe = $this->safeEvidence($candidate);
                if ($candidateId !== null && in_array($candidateId, $duplicateTradeIds, true)) {
                    $safe['ambiguity_count'] = collect($rows)->filter(fn ($row) => is_array($row)
                        && (string) ($row['trade_id'] ?? '') === $candidateId
                        && $this->isCncCandidate($row))->count();
                    $safe['candidate_variants'] = $cncRows->filter(fn ($row) => (string) ($row['trade_id'] ?? '') === $candidateId)
                        ->take(self::MAX_DUPLICATE_VARIANTS)->map(fn ($row) => $this->safeEvidence($row))->values()->all();
                }
                $identityEvidence = $safe;
                if (! preg_match('/^[0-9]{1,64}$/', (string) ($safe['trade_id'] ?? ''))) {
                    $sameEvidence = $cncRows->filter(function ($row) use ($safe): bool {
                        if (preg_match('/^[0-9]{1,64}$/', (string) ($row['trade_id'] ?? ''))) return false;
                        return hash_equals(
                            hash('sha256', json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                            hash('sha256', json_encode($this->safeEvidence($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                        );
                    })->values();
                    if ($sameEvidence->count() > 1) {
                        $safe['ambiguity_count'] = $sameEvidence->count();
                        $safe['candidate_variants'] = $sameEvidence->take(self::MAX_DUPLICATE_VARIANTS)
                            ->map(fn ($row) => $this->safeEvidence($row))->values()->all();
                    }
                }
                $key = hash('sha256', is_numeric($safe['trade_id'] ?? null)
                    ? 'trade:'.(string) $safe['trade_id']
                    : 'candidate:'.json_encode($identityEvidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $created = DB::transaction(function () use ($profile, $run, $brokerAccountId, $safe, &$key): bool {
                    $this->assertCurrentBrokerAccount($profile, $brokerAccountId);
                    $existingEvidence = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                        ->where('evidence_key', $key)->lockForUpdate()->first();
                    if ($existingEvidence && $existingEvidence->evidence_kind !== 'malformed_candidate'
                        && is_numeric($safe['trade_id'] ?? null)) {
                        // A malformed replay must not mutate a previously booked execution.
                        // Give the exception its own stable, profile-scoped review identity.
                        $key = hash('sha256', 'malformed-trade:'.(string) $safe['trade_id']);
                        $existingEvidence = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                            ->where('evidence_key', $key)->lockForUpdate()->first();
                    }
                    if ($existingEvidence && $existingEvidence->evidence_kind === 'malformed_candidate'
                        && $existingEvidence->broker_evidence !== $safe
                        && in_array($existingEvidence->status, ['unresolved_candidate', 'acknowledged_unresolved'], true)) {
                        $existingEvidence->forceFill(['broker_evidence' => $safe,
                            'symbol' => mb_substr((string) ($safe['tradingsymbol'] ?? 'UNKNOWN'), 0, 64),
                            'exchange' => mb_substr((string) ($safe['exchange'] ?? 'UNKNOWN'), 0, 8),
                            'product' => mb_substr((string) ($safe['product'] ?? 'UNKNOWN'), 0, 16),
                            'side' => in_array($safe['transaction_type'] ?? '', ['BUY', 'SELL'], true) ? strtolower($safe['transaction_type']) : 'unknown',
                            'quantity' => $this->positiveDecimal($safe['quantity'] ?? null) ?? 0,
                            'price' => $this->positiveDecimal($safe['price'] ?? null) ?? 0,
                            'reviewed_by' => null, 'reviewed_at' => null, 'review_resolution' => null, 'status' => 'unresolved_candidate'])->save();
                    }
                    if ($existingEvidence) return false;
                    $brokerTradeId = ctype_digit((string) ($safe['trade_id'] ?? '')) && strlen((string) $safe['trade_id']) <= 64
                        ? (string) $safe['trade_id'] : null;
                    if ($brokerTradeId !== null && KiteCncTradeImport::query()->where('profile_id', $profile->id)
                        ->where('broker_trade_id', $brokerTradeId)->lockForUpdate()->exists()) {
                        // Preserve a separate malformed duplicate without colliding with the booked broker ID.
                        $brokerTradeId = 'candidate:'.substr(hash('sha256', 'trade:'.$brokerTradeId), 0, 40);
                    }
                    KiteCncTradeImport::query()->create([
                        'profile_id' => $profile->id, 'broker_account_id' => $brokerAccountId, 'sync_run_id' => $run->id, 'stock_id' => null,
                        'broker_trade_id' => $brokerTradeId ?? 'candidate:'.substr($key, 0, 40),
                        'broker_order_id' => is_scalar($safe['order_id'] ?? null) ? mb_substr((string) $safe['order_id'], 0, 64) : null,
                        'transaction_id' => null, 'matched_transaction_id' => null,
                        'symbol' => mb_substr((string) ($safe['tradingsymbol'] ?? 'UNKNOWN'), 0, 64),
                        'exchange' => mb_substr((string) ($safe['exchange'] ?? 'UNKNOWN'), 0, 8),
                        'side' => in_array($safe['transaction_type'] ?? '', ['BUY', 'SELL'], true) ? strtolower($safe['transaction_type']) : 'unknown',
                        'quantity' => $this->positiveDecimal($safe['quantity'] ?? null) ?? 0,
                        'price' => $this->positiveDecimal($safe['price'] ?? null) ?? 0,
                        'executed_at' => null, 'product' => mb_substr((string) ($safe['product'] ?? 'UNKNOWN'), 0, 16), 'status' => 'unresolved_candidate',
                        'evidence_kind' => 'malformed_candidate', 'evidence_key' => $key,
                        'broker_evidence' => $safe,
                    ]);
                    return true;
                });
                if ($created) $review++;
            }
            foreach ($prepared as $trade) {
                DB::transaction(function () use ($profile, $run, $brokerAccountId, $trade, $manualMatches, &$imported, &$matched, &$review): void {
                    $this->assertCurrentBrokerAccount($profile, $brokerAccountId);
                    $prior = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                        ->where('broker_trade_id', $trade['trade_id'])->lockForUpdate()->first();
                    if ($prior) {
                        if ($prior->evidence_kind === 'legacy_unbound') {
                            // A pre-provenance ID may belong to another Kite
                            // account. Preserve the old row and record this
                            // account's colliding fill separately as unresolved.
                            $conflictKey = hash('sha256', 'account-conflict:'.$brokerAccountId.':trade:'.$trade['trade_id']);
                            $conflict = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                                ->where('evidence_key', $conflictKey)->lockForUpdate()->first();
                            if (! $conflict) {
                                $safeEvidence = $this->safeEvidence($trade['raw']);
                                KiteCncTradeImport::query()->create([
                                    'profile_id' => $profile->id, 'broker_account_id' => $brokerAccountId,
                                    'sync_run_id' => $run->id, 'stock_id' => $trade['stock']->id,
                                    'broker_trade_id' => 'candidate:'.substr($conflictKey, 0, 40),
                                    'broker_order_id' => $safeEvidence['order_id'] ?? null,
                                    'transaction_id' => null, 'matched_transaction_id' => null,
                                    'symbol' => $trade['symbol'], 'exchange' => $trade['exchange'],
                                    'side' => $trade['side'], 'quantity' => $trade['quantity'], 'price' => $trade['price'],
                                    'executed_at' => $trade['executed_at'], 'product' => 'CNC',
                                    'status' => 'unresolved_candidate', 'evidence_kind' => 'account_conflict',
                                    'evidence_key' => $conflictKey, 'broker_evidence' => $safeEvidence,
                                ]);
                                $review++;
                            }
                            return;
                        }
                        // A later well-formed replay can enrich a malformed row, but
                        // must not turn that prior unresolved candidate into a write.
                        if ($prior->evidence_kind === 'malformed_candidate' && in_array($prior->status, ['unresolved_candidate', 'acknowledged_unresolved'], true)) {
                            $evidence = $this->safeEvidence($trade['raw']);
                            $previousEvidence = (array) $prior->broker_evidence;
                            if (isset($previousEvidence['ambiguity_count'])) {
                                $evidence['ambiguity_count'] = max((int) $previousEvidence['ambiguity_count'], 1);
                                $evidence['candidate_variants'] = array_slice((array) ($previousEvidence['candidate_variants'] ?? []), 0, self::MAX_DUPLICATE_VARIANTS);
                            }
                            $prior->forceFill(['stock_id' => $trade['stock']->id, 'symbol' => $trade['symbol'], 'exchange' => $trade['exchange'],
                                'side' => $trade['side'], 'quantity' => $trade['quantity'], 'price' => $trade['price'],
                                'executed_at' => $trade['executed_at'], 'evidence_kind' => 'execution', 'status' => 'manual_match_review',
                                'review_resolution' => null, 'reviewed_by' => null, 'reviewed_at' => null,
                                'broker_order_id' => $this->safeEvidence($trade['raw'])['order_id'] ?? null,
                                'broker_evidence' => $evidence])->save();
                            $review++;
                        }
                        return;
                    }
                    // manualMatches partitions by execution venue as well as
                    // stock, date, and side. Keep the lookup key identical so
                    // an NSE execution cannot miss (or reuse) its venue-scoped
                    // manual match decision.
                    $groupKey = $trade['stock']->id.'|'.$trade['date'].'|'.$trade['side'].'|'.$trade['exchange'];
                    $manual = $manualMatches[$groupKey][$trade['trade_id']] ?? null;
                    $status = 'imported';
                    $transaction = null;
                    $matchedTransaction = null;
                    if (is_array($manual) && ($manual['kind'] ?? '') === 'match') {
                        $candidate = $manual['transaction'];
                        $locked = Transaction::query()->where('profile_id', $profile->id)->whereKey($candidate->id)->lockForUpdate()->first();
                        $allowedTradeIds = collect($manualMatches[$groupKey] ?? [])->filter(fn ($match) =>
                            is_array($match) && ($match['kind'] ?? '') === 'match'
                            && (int) $match['transaction']->id === (int) $candidate->id
                        )->keys()->all();
                        $linkedElsewhere = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                            ->where('matched_transaction_id', $candidate->id)
                            ->whereNotIn('broker_trade_id', $allowedTradeIds)->exists();
                        $stillMatches = $locked
                            && (int) $locked->stock_id === (int) $candidate->stock_id
                            && strtoupper((string) $locked->execution_exchange) === $trade['exchange']
                            && $locked->type === $candidate->type
                            && $locked->transaction_date?->toDateString() === $candidate->transaction_date?->toDateString()
                            && (string) $locked->quantity === (string) $candidate->quantity
                            && (string) $locked->price === (string) $candidate->price;
                        if (! $linkedElsewhere && $stillMatches) {
                            $status = $candidate->source === Transaction::SOURCE_MANUAL ? 'matched_manual' : 'matched_existing';
                            $matchedTransaction = $locked;
                            $matched++;
                        } else {
                            $status = 'manual_match_review';
                            $review++;
                        }
                    } elseif (is_array($manual)) {
                        $status = 'manual_match_review';
                        $review++;
                    } else {
                        try {
                            $transaction = $this->writes->create($profile, $trade['stock'], [
                                'type' => $trade['side'], 'quantity' => $trade['quantity'], 'price' => $trade['price'],
                                'execution_exchange' => $trade['exchange'], 'transaction_date' => $trade['date'],
                                'source' => Transaction::SOURCE_OTHER,
                                'notes' => 'Kite CNC execution '.$trade['trade_id'],
                            ], user: null);
                            $imported++;
                        } catch (Throwable $error) {
                            // A post-commit snapshot side effect can fail after the
                            // ledger write succeeded. Detect that durable write so
                            // the broker fill is not mislabeled or imported twice.
                            $transaction = Transaction::query()->where('profile_id', $profile->id)
                                ->where('stock_id', $trade['stock']->id)->whereDate('transaction_date', $trade['date'])
                                ->where(function ($query) use ($trade): void {
                                    $query->where('notes', 'Kite CNC execution '.$trade['trade_id'])
                                        ->orWhere('notes', 'like', 'Kite CNC execution '.$trade['trade_id'].' (order %');
                                })->first();
                            if ($transaction) {
                                $imported++;
                            } else {
                                // Attribution/validation failures are reviewed, never guessed.
                                $status = 'manual_match_review';
                                $review++;
                            }
                        }
                    }
                    $orderId = $this->safeEvidence($trade['raw'])['order_id'] ?? null;
                    KiteCncTradeImport::query()->create([
                        'profile_id' => $profile->id, 'broker_account_id' => $brokerAccountId, 'sync_run_id' => $run->id,
                        'stock_id' => $trade['stock']->id,
                        'broker_trade_id' => $trade['trade_id'],
                        'broker_order_id' => $orderId,
                        'transaction_id' => $transaction?->id, 'matched_transaction_id' => $matchedTransaction?->id,
                        'symbol' => $trade['symbol'], 'exchange' => $trade['exchange'], 'side' => $trade['side'],
                        'quantity' => $trade['quantity'], 'price' => $trade['price'], 'executed_at' => $trade['executed_at'],
                        'product' => 'CNC', 'status' => $status, 'evidence_kind' => 'execution',
                        'evidence_key' => hash('sha256', 'trade:'.$trade['trade_id']),
                        'broker_evidence' => $this->safeEvidence($trade['raw']),
                    ]);
                });
            }
            $review = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                ->whereIn('status', ['manual_match_review', 'unresolved_candidate', 'acknowledged_unresolved'])
                ->whereHas('syncRun', fn ($query) => $query->whereDate('trading_date', $run->trading_date->toDateString()))
                ->count();
            $run->forceFill(['status' => $review > 0 ? 'needs_review' : 'completed', 'received_count' => count($rows),
                'eligible_count' => $eligible->count(), 'imported_count' => $imported, 'matched_count' => $matched,
                'review_count' => $review, 'completed_at' => now()])->save();
            $this->recordUnavailableTradingDayGaps($profile, $run->trading_date->toDateString(), $brokerAccountId);
            return compact('run', 'imported', 'matched', 'review');
        } catch (Throwable $error) {
            // Provider exceptions can contain credentials, personal details or
            // complete response bodies. Persist only a fixed safe summary.
            $run->forceFill(['status' => 'sync_failed', 'failure' => 'Kite CNC sync did not complete. Check the connection and retry.', 'completed_at' => now()])->save();
            throw new \RuntimeException('Kite CNC sync did not complete. Check the connection and retry.');
        }
    }

    /** Keep only non-secret execution fields; provider payloads are never stored verbatim. */
    private function safeEvidence(array $row): array
    {
        $safe = [];
        foreach (['trade_id', 'order_id'] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && strlen((string) $value) <= 64
                && ($key === 'trade_id' ? preg_match('/^[0-9]{1,64}$/', (string) $value) : preg_match('/^(?:[0-9]{1,64}|[A-Z]{1,4}-[0-9]{1,20})$/', (string) $value))) {
                $safe[$key] = (string) $value;
            }
        }
        $rawSymbol = $row['tradingsymbol'] ?? '';
        $symbol = is_string($rawSymbol) && strlen($rawSymbol) <= 32 ? strtoupper(trim($rawSymbol)) : '';
        if (preg_match('/^[A-Z0-9&._-]{1,32}$/', $symbol)) $safe['tradingsymbol'] = $symbol;
        $rawExchange = $row['exchange'] ?? '';
        $exchange = is_string($rawExchange) && strlen($rawExchange) <= 8 ? strtoupper(trim($rawExchange)) : '';
        if (in_array($exchange, ['NSE', 'BSE'], true)) $safe['exchange'] = $exchange;
        $rawSide = $row['transaction_type'] ?? '';
        $side = is_string($rawSide) && strlen($rawSide) <= 8 ? strtoupper(trim($rawSide)) : '';
        if (in_array($side, ['BUY', 'SELL'], true)) $safe['transaction_type'] = $side;
        $rawProduct = $row['product'] ?? '';
        $product = is_string($rawProduct) && strlen($rawProduct) <= 16 ? strtoupper(trim($rawProduct)) : '';
        if (in_array($product, ['CNC', 'MIS', 'NRML', 'MTF'], true)) $safe['product'] = $product;
        foreach (['quantity', 'price'] as $key) {
            $value = $this->positiveDecimal($row[$key] ?? null);
            if ($value !== null) $safe[$key] = number_format($value, 4, '.', '');
        }
        try {
            if (is_string($row['exchange_timestamp'] ?? null) && strlen($row['exchange_timestamp']) <= 64
                && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:?[0-9]{2})?$/', $row['exchange_timestamp'])) {
                $safe['exchange_timestamp'] = \Illuminate\Support\Carbon::parse($row['exchange_timestamp'], 'Asia/Kolkata')->timezone('Asia/Kolkata')->toIso8601String();
            }
        } catch (Throwable) {
            // Invalid source timestamps are omitted from safe persisted evidence.
        }
        return $safe;
    }

    private function assertCurrentBrokerAccount(PortfolioProfile $profile, string $brokerAccountId): void
    {
        $connection = BrokerConnection::query()->where('user_id', $profile->user_id)
            ->where('provider', BrokerConnection::PROVIDER_KITE)->lockForUpdate()->first();
        $currentAccountId = trim((string) ($connection?->broker_user_id ?? ''));
        if ($currentAccountId === '' || ! hash_equals($brokerAccountId, $currentAccountId)) {
            throw new \RuntimeException('The connected Kite account changed during CNC sync; no further rows were processed.');
        }
    }

    /** Reject oversized provider payloads before parsing or persisting any row. */
    private function assertProviderRowsBounded(array $rows): void
    {
        $totalBytes = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) > 64) {
                throw new \RuntimeException('Kite trade source contains an oversized row; no rows were processed.');
            }
            foreach ($row as $key => $value) {
                if ((! is_string($key) && ! is_int($key)) || strlen((string) $key) > 64) {
                    throw new \RuntimeException('Kite trade source contains an unsupported row shape; no rows were processed.');
                }
                $totalBytes += strlen((string) $key);
                $this->measureProviderValue($value, 1, $totalBytes);
            }
        }
    }

    private function measureProviderValue(mixed $value, int $depth, int &$totalBytes): void
    {
        if ($depth > 4) throw new \RuntimeException('Kite trade source contains an overly nested row; no rows were processed.');
        if (is_array($value)) {
            if (count($value) > 64) throw new \RuntimeException('Kite trade source contains an oversized nested field; no rows were processed.');
            foreach ($value as $key => $nested) {
                if ((! is_string($key) && ! is_int($key)) || strlen((string) $key) > 64) {
                    throw new \RuntimeException('Kite trade source contains an unsupported nested field; no rows were processed.');
                }
                $totalBytes += strlen((string) $key);
                $this->measureProviderValue($nested, $depth + 1, $totalBytes);
            }
            return;
        }
        if (! is_null($value) && ! is_scalar($value)) {
            throw new \RuntimeException('Kite trade source contains an unsupported row value; no rows were processed.');
        }
        if (is_string($value) && strlen($value) > 1024) {
            throw new \RuntimeException('Kite trade source contains an oversized field; no rows were processed.');
        }
        $totalBytes += is_string($value) ? strlen($value) : 16;
        if ($totalBytes > 5_000_000) {
            throw new \RuntimeException('Kite trade source exceeded the bounded size limit; no rows were processed.');
        }
    }

    /** Missing/unknown products on equity exchanges are retained for review, never imported. */
    private function isCncCandidate(array $row): bool
    {
        $product = is_string($row['product'] ?? null) ? strtoupper(trim($row['product'])) : '';
        $exchange = is_string($row['exchange'] ?? null) ? strtoupper(trim($row['exchange'])) : '';
        if ($product === 'CNC') {
            return $exchange === '' || in_array($exchange, ['NSE', 'BSE'], true)
                || ! in_array($exchange, ['NFO', 'BFO', 'CDS', 'MCX'], true);
        }
        return ! in_array($product, ['MIS', 'NRML', 'MTF'], true)
            && in_array($exchange, ['NSE', 'BSE'], true);
    }

    private function positiveDecimal(mixed $value): ?float
    {
        if (! is_numeric($value)) return null;
        $number = (float) $value;
        if (! is_finite($number) || $number <= 0 || $number > 99999999999999) return null;
        return round($number, 4);
    }

    private function parseExecutionTime(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || strlen($value) > 64
            || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:?[0-9]{2})?$/', $value, $parts)) {
            return null;
        }
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) return null;
        try {
            return \Illuminate\Support\Carbon::parse($value, 'Asia/Kolkata')->timezone('Asia/Kolkata');
        } catch (Throwable) {
            return null;
        }
    }

    private function recordUnavailableTradingDayGaps(PortfolioProfile $profile, string $today, string $brokerAccountId): void
    {
        $last = KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)
            ->where('broker_account_id', $brokerAccountId)
            ->whereDate('trading_date', '<', $today)->whereIn('status', ['completed', 'needs_review'])
            ->orderByDesc('trading_date')->first();
        if (! $last) return; // First connection has no known missing interval to report.
        $cursor = \Illuminate\Support\Carbon::parse($last->trading_date->toDateString(), 'Asia/Kolkata')->addDay();
        $end = \Illuminate\Support\Carbon::parse($today, 'Asia/Kolkata')->subDay();
        while ($cursor->lte($end)) {
            if (\App\Support\TradingCalendar::isEquitySessionDate($cursor)
                && ! KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)->where('broker_account_id', $brokerAccountId)
                    ->whereDate('trading_date', $cursor->toDateString())
                    ->whereIn('status', ['completed', 'needs_review', 'gap_unavailable'])->exists()) {
                KiteCncTradeSyncRun::query()->create([
                    'profile_id' => $profile->id, 'user_id' => $profile->user_id, 'broker_account_id' => $brokerAccountId,
                    'trading_date' => $cursor->toDateString(),
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
        $keys = $trades->map(fn ($t) => $t['stock']->id.'|'.$t['date'].'|'.$t['side'].'|'.$t['exchange'])->unique();
        $result = [];
        foreach ($keys as $key) {
            [$stockId, $date, $side, $exchange] = explode('|', $key);
            $rows = $trades->filter(fn ($t) => $t['stock']->id === (int) $stockId && $t['date'] === $date && $t['side'] === $side && $t['exchange'] === $exchange);
            $quantity = (float) $rows->sum('quantity');
            $turnover = (float) $rows->sum(fn ($t) => $t['quantity'] * $t['price']);
            $existing = Transaction::query()->where('profile_id', $profile->id)->where('stock_id', (int) $stockId)
                ->where('type', $side)->whereDate('transaction_date', $date)
                ->where('execution_exchange', $exchange)
                ->whereNotIn('id', KiteCncTradeImport::query()->where('profile_id', $profile->id)
                    ->whereNotNull('matched_transaction_id')->select('matched_transaction_id'))
                ->whereNotIn('id', KiteCncTradeImport::query()->where('profile_id', $profile->id)
                    ->whereNotNull('transaction_id')->select('transaction_id'))->get();
            if ($existing->isEmpty()) continue;
            $priorImportsExist = KiteCncTradeImport::query()->where('profile_id', $profile->id)
                ->where('stock_id', (int) $stockId)->where('side', $side)
                ->where('exchange', $exchange)
                ->whereDate('executed_at', $date)->exists();
            if ($priorImportsExist) {
                // A previously unmatched manual transaction could represent
                // fills already imported before this snapshot was complete.
                // Do not auto-link or write more fills against that ambiguous
                // ledger state.
                foreach ($rows as $trade) $result[$key][$trade['trade_id']] = ['kind' => 'review'];
                continue;
            }

            // Prefer a one-to-one match when the operator entered each partial
            // fill separately. Identical fills are paired by stable row order
            // only when the complete candidate multiset has the same counts.
            $remaining = $existing->sortBy('id')->values();
            $matchesByTrade = [];
            $oneToOne = $existing->count() === $rows->count();
            foreach ($rows as $trade) {
                $matches = $remaining->filter(fn ($tx) => abs((float) $tx->quantity - (float) $trade['quantity']) < 0.0001
                    && abs((float) $tx->price - (float) $trade['price']) < 0.0001)->values();
                $identicalFillCount = $rows->filter(fn ($candidate) => abs((float) $candidate['quantity'] - (float) $trade['quantity']) < 0.0001
                    && abs((float) $candidate['price'] - (float) $trade['price']) < 0.0001)->count();
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
                && abs((float) $tx->price - $turnover / max($quantity, 0.0001)) < 0.0001);
            if ($aggregateMatches->count() === 1) {
                foreach ($rows as $trade) $result[$key][$trade['trade_id']] = ['kind' => 'match', 'transaction' => $aggregateMatches->first()];
            } else {
                foreach ($rows as $trade) $result[$key][$trade['trade_id']] = ['kind' => 'review'];
            }
        }
        return $result;
    }
}
