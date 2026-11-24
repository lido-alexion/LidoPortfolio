<?php

namespace App\Services\Bhavcopy;

use App\Models\Stock;
use App\Models\CorporateAction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Quarantines configured pre-action NSE bars until an audited F020/F042 repair proof exists. */
class NseHistoricalPriceAdjustmentGate
{
    private const FINGERPRINT_FIELDS = [
        'id', 'stock_id', 'price_date', 'open_price', 'high_price', 'low_price', 'close_price',
        'adjusted_close_price', 'volume', 'provider_source', 'data_source', 'created_at',
    ];

    public function requiresAdjustmentProofFor(Stock $stock, string $priceDate): bool
    {
        if (strtoupper((string) $stock->exchange) !== 'NSE') return false;
        foreach ($this->transitions() as $transition) {
            if (($stock->symbol === ($transition['symbol'] ?? null)
                    || $stock->isin === ($transition['new_isin'] ?? null))
                && $priceDate < (string) ($transition['effective_on'] ?? '')) return true;
        }

        return false;
    }

    /** Whether an OHLCV restatement for this stock overlaps the protected pre-transition history. */
    public function restatementTouchesProtectedHistory(Stock $stock): bool
    {
        foreach ($this->transitions() as $transition) {
            $effectiveOn = (string) ($transition['effective_on'] ?? '');
            if ($effectiveOn !== '' && $this->requiresAdjustmentProofFor(
                $stock,
                \Carbon\Carbon::parse($effectiveOn)->subDay()->toDateString(),
            )) {
                return true;
            }
        }

        return false;
    }

    /** Apply the adjustment quarantine to a query-builder price read. */
    public function apply(Builder $query, string $priceTable): Builder
    {
        foreach ($this->transitions() as $transition) {
            $symbol = (string) ($transition['symbol'] ?? '');
            $canonicalIsin = (string) ($transition['new_isin'] ?? '');
            $effectiveOn = (string) ($transition['effective_on'] ?? '');
            if ($symbol === '' || $canonicalIsin === '' || $effectiveOn === '') continue;

            $untrustedStockIds = $this->untrustedStockIdsForTransition($transition);
            $query->where(function (Builder $visible) use ($priceTable, $symbol, $canonicalIsin, $effectiveOn, $untrustedStockIds): void {
                $visible->whereDate($priceTable.'.price_date', '>=', $effectiveOn)
                    ->orWhereNotExists(fn (Builder $stock) => $stock->selectRaw('1')
                        ->from('portfolio_stocks as nse_price_gate_stock')
                        ->whereColumn('nse_price_gate_stock.id', $priceTable.'.stock_id')
                        ->where('nse_price_gate_stock.exchange', 'NSE')
                        ->where(function (Builder $identity) use ($symbol, $canonicalIsin): void {
                            $identity->where('nse_price_gate_stock.symbol', $symbol)
                                ->orWhere('nse_price_gate_stock.isin', $canonicalIsin);
                        }))
                    ->orWhereNotIn($priceTable.'.stock_id', $untrustedStockIds);
            });
        }

        return $query;
    }

    /** Add unresolved adjustment evidence stock IDs to the existing DQ eligibility guard. */
    public function quarantinedStockIds(array $stockIds): array
    {
        $stockIds = array_values(array_unique(array_map('intval', $stockIds)));
        if ($stockIds === []) return [];
        $blocked = [];
        foreach ($this->transitions() as $transition) {
            $query = DB::table('portfolio_stocks as nse_price_gate_stock')->select('nse_price_gate_stock.id')
                ->whereIn('nse_price_gate_stock.id', $stockIds)
                ->where('nse_price_gate_stock.exchange', 'NSE')
                ->where(function (Builder $identity) use ($transition): void {
                    $identity->where('nse_price_gate_stock.symbol', $transition['symbol'])
                        ->orWhere('nse_price_gate_stock.isin', $transition['new_isin']);
                });
            foreach ($query->pluck('id') as $id) {
                if (! $this->hasCurrentAdjustmentProof((int) $id, $transition)) $blocked[(int) $id] = true;
            }
        }

        return $blocked;
    }

    /** Return only narrowly affected stock IDs whose current physical bars fail proof validation. */
    public function applyStockEligibility(Builder $query, string $stockTable, ?array $untrustedStockIds = null): Builder
    {
        if ($this->transitions() === []) return $query;
        $query->whereIn($stockTable.'.id', $untrustedStockIds ?? $this->untrustedStockIds());

        return $query;
    }

    /** @return list<int> Stocks whose current physical history lacks a valid proof. */
    public function untrustedStockIds(): array
    {
        $ids = [];
        foreach ($this->transitions() as $transition) {
            $ids = array_merge($ids, $this->untrustedStockIdsForTransition($transition));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Fingerprint the complete physical pre-action history after a reviewed repair.
     * IDs and source provenance are included, so a same-count overwrite is distinct.
     *
     * @return array{rows_adjusted:int, rows_sha256:string}
     */
    public function historyEvidence(int $stockId, string $effectiveOn): array
    {
        $rows = DB::table('portfolio_stock_prices')->where('stock_id', $stockId)
            ->where('price_date', '<', $effectiveOn)->orderBy('price_date')->orderBy('id')
            ->get(self::FINGERPRINT_FIELDS)->all();
        $normalized = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $values = [];
            foreach (self::FINGERPRINT_FIELDS as $field) {
                $value = $row[$field] ?? null;
                $values[$field] = match ($field) {
                    'id', 'stock_id' => $value === null ? null : (int) $value,
                    'price_date' => $value === null ? null : substr((string) $value, 0, 10),
                    default => $value === null ? null : (string) $value,
                };
            }
            $normalized[] = $values;
        }

        return [
            'rows_adjusted' => count($rows),
            'rows_sha256' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** Validate a persisted F020 price-restatement proof against current rows. */
    public function hasCurrentCorporateActionProof(CorporateAction $action): bool
    {
        $metadata = $this->decodeMetadata($action->metadata);
        $proof = $metadata['price_adjustment'] ?? [];
        if (! is_array($proof) || isset($proof['repair_note'])) return false;

        $actionTypes = in_array($action->action_type, ['split', 'face_value_split'], true)
            ? ['split', 'face_value_split']
            : [$action->action_type];
        $actions = DB::table('portfolio_corporate_actions')->where('stock_id', $action->stock_id)
            ->whereIn('action_type', $actionTypes)->whereDate('ex_date', $action->ex_date->toDateString())
            ->whereNotNull('applied_at')->get(['id', 'ratio_from', 'ratio_to', 'metadata']);
        $validProofIds = [];
        foreach ($actions as $candidate) {
            $candidateMetadata = $this->decodeMetadata($candidate->metadata);
            $candidateProof = $candidateMetadata['price_adjustment'] ?? [];
            if ((int) $candidate->ratio_from !== (int) $action->ratio_from
                || (int) $candidate->ratio_to !== (int) $action->ratio_to) return false;
            if (is_array($candidateProof) && ! isset($candidateProof['repair_note'])
                && (int) ($candidateProof['rows_adjusted'] ?? 0) > 0) {
                $validProofIds[] = (int) $candidate->id;
            }
        }
        if (count($validProofIds) !== 1 || $validProofIds[0] !== (int) $action->id) return false;

        return $this->referenceMatchesEvidence([
            'type' => 'action',
            'metadata' => $metadata,
        ], $this->historyEvidence((int) $action->stock_id, $action->ex_date->toDateString()));
    }

    /** Invalidate old repair proof before the only authorized OHLCV restatement writer runs. */
    public function invalidateRepairProof(Stock $stock): array
    {
        // Every ex-date restates rows before it. Earlier actions therefore also
        // overlap the protected pre-transition interval.
        if (! $this->restatementTouchesProtectedHistory($stock)) return [];

        $references = $this->completedProofReferences((int) $stock->id);
        foreach ($references as $reference) {
            if (! $reference['has_hash'] || ! $this->referenceMatchesCurrentHistory($reference)) {
                throw new \RuntimeException('Overlapping corporate-action repair proof is stale; refusing to restate already-adjusted history.');
            }
        }

        foreach ($references as $reference) {
            if ($reference['type'] !== 'factor') continue;
            $factor = (object) ['id' => $reference['id'], 'metadata' => $reference['metadata']];
            $metadata = $this->decodeMetadata($factor->metadata);
            unset($metadata['ohlcv_repair']['rows_sha256']);
            DB::table('portfolio_price_adjustment_factors')->where('id', $factor->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
        }

        foreach ($references as $reference) {
            if ($reference['type'] !== 'action') continue;
            $action = (object) ['id' => $reference['id'], 'metadata' => $reference['metadata']];
            $metadata = $this->decodeMetadata($action->metadata);
            unset($metadata['price_adjustment']['rows_sha256']);
            DB::table('portfolio_corporate_actions')->where('id', $action->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
        }

        return $references;
    }

    /** Refresh only proofs validated before this restatement; callers must wrap writes in a transaction. */
    public function refreshOverlappingRepairProofs(Stock $stock, array $references): void
    {
        foreach ($references as $reference) {
            $evidence = $this->historyEvidence((int) $stock->id, $reference['effective_on']);
            $metadata = $this->decodeMetadata($reference['metadata']);
            if ($reference['type'] === 'factor') {
                $metadata['ohlcv_repair']['rows_adjusted'] = $evidence['rows_adjusted'];
                $metadata['ohlcv_repair']['rows_sha256'] = $evidence['rows_sha256'];
                DB::table('portfolio_price_adjustment_factors')->where('id', $reference['id'])
                    ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
            } else {
                $metadata['price_adjustment']['rows_adjusted'] = $evidence['rows_adjusted'];
                $metadata['price_adjustment']['rows_sha256'] = $evidence['rows_sha256'];
                DB::table('portfolio_corporate_actions')->where('id', $reference['id'])
                    ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) return $metadata;
        if (! is_string($metadata)) return [];
        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function untrustedStockIdsForTransition(array $transition): array
    {
        $query = DB::table('portfolio_stocks')->select('id')->where('exchange', 'NSE')
            ->where(function (Builder $identity) use ($transition): void {
                $identity->where('symbol', $transition['symbol'])->orWhere('isin', $transition['new_isin']);
            });

        return $query->pluck('id')->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => ! $this->hasCurrentAdjustmentProof($id, $transition))
            ->values()->all();
    }

    private function hasCurrentAdjustmentProof(int $stockId, array $transition): bool
    {
        $effectiveOn = (string) $transition['effective_on'];
        $proofs = $this->completedProofReferences($stockId);
        $matching = [];
        foreach ($proofs as $proof) {
            if ($proof['effective_on'] !== $effectiveOn || ! $this->proofMatchesTransition($proof, $transition)) continue;
            $matching[] = $proof;
        }

        // Multiple completed proof records for the same stock event can be a
        // legacy double-restatement. A current fingerprint alone cannot prove
        // that the event was applied exactly once.
        if (count($matching) !== 1) return false;

        return $this->referenceMatchesEvidence(
            $matching[0],
            $this->historyEvidence($stockId, $effectiveOn),
        );

    }

    private function proofMatchesTransition(array $proof, array $transition): bool
    {
        $metadata = $this->decodeMetadata($proof['metadata']);
        if ($proof['type'] === 'factor') {
            return (bool) $proof['is_active']
                && in_array($proof['action_type'], ['split', 'face_value_split'], true)
                && (float) $proof['price_divisor'] === (float) $transition['price_divisor']
                && ($metadata['ohlcv_repair_status'] ?? null) === 'completed';
        }

        return $proof['applied_at'] !== null
            && in_array($proof['action_type'], ['split', 'face_value_split'], true)
            && (int) $proof['ratio_from'] === 1
            && (float) $proof['ratio_to'] === (float) $transition['price_divisor'];
    }

    private function referenceMatchesCurrentHistory(array $reference): bool
    {
        return $this->referenceMatchesEvidence(
            $reference,
            $this->historyEvidence((int) $reference['stock_id'], $reference['effective_on']),
        );
    }

    private function referenceMatchesEvidence(array $reference, array $evidence): bool
    {
        $metadata = $this->decodeMetadata($reference['metadata']);
        $proof = $reference['type'] === 'factor'
            ? ($metadata['ohlcv_repair'] ?? [])
            : ($metadata['price_adjustment'] ?? []);
        $hash = $proof['rows_sha256'] ?? null;

        return $evidence['rows_adjusted'] > 0
            && (int) ($proof['rows_adjusted'] ?? 0) === $evidence['rows_adjusted']
            && is_string($hash)
            && preg_match('/^[a-f0-9]{64}$/', $hash) === 1
            && hash_equals($hash, $evidence['rows_sha256']);
    }

    /** @return list<array<string, mixed>> */
    private function completedProofReferences(int $stockId): array
    {
        $references = [];
        foreach (DB::table('portfolio_price_adjustment_factors')->where('stock_id', $stockId)
            ->where('is_active', true)->get(['id', 'stock_id', 'effective_ex_date', 'action_type', 'price_divisor', 'metadata', 'is_active']) as $factor) {
            $metadata = $this->decodeMetadata($factor->metadata);
            if (($metadata['ohlcv_repair_status'] ?? null) !== 'completed') continue;
            if (! in_array($factor->action_type, ['split', 'face_value_split'], true)) continue;
            $references[] = [
                'type' => 'factor', 'id' => (int) $factor->id, 'stock_id' => (int) $factor->stock_id,
                'effective_on' => substr((string) $factor->effective_ex_date, 0, 10),
                'action_type' => (string) $factor->action_type, 'price_divisor' => (float) $factor->price_divisor,
                'metadata' => $metadata, 'is_active' => (bool) $factor->is_active,
                'has_hash' => isset($metadata['ohlcv_repair']['rows_sha256']),
            ];
        }
        foreach (DB::table('portfolio_corporate_actions')->where('stock_id', $stockId)
            ->whereNotNull('applied_at')->get(['id', 'stock_id', 'ex_date', 'action_type', 'ratio_from', 'ratio_to', 'applied_at', 'metadata']) as $action) {
            $metadata = $this->decodeMetadata($action->metadata);
            $priceProof = $metadata['price_adjustment'] ?? [];
            if (! in_array($action->action_type, ['split', 'face_value_split'], true)) continue;
            if (! is_array($priceProof) || $priceProof === [] || isset($priceProof['repair_note'])) continue;
            $references[] = [
                'type' => 'action', 'id' => (int) $action->id, 'stock_id' => (int) $action->stock_id,
                'effective_on' => substr((string) $action->ex_date, 0, 10),
                'action_type' => (string) $action->action_type, 'ratio_from' => (int) $action->ratio_from,
                'ratio_to' => (float) $action->ratio_to, 'applied_at' => $action->applied_at,
                'metadata' => $metadata, 'has_hash' => isset($priceProof['rows_sha256']) && ! isset($priceProof['repair_note']),
            ];
        }

        return $references;
    }

    /** @return array<int, array<string, mixed>> */
    private function transitions(): array
    {
        return array_values(array_filter(
            config('universe_nse_price_identities.transitions', []),
            fn ($transition) => is_array($transition) && ! empty($transition['requires_price_adjustment_proof']),
        ));
    }
}
