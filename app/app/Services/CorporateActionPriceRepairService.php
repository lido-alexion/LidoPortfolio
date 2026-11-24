<?php

namespace App\Services;

use App\Models\CorporateAction;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Support\Facades\DB;

class CorporateActionPriceRepairService
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING_METADATA = 'missing_metadata';

    public const STATUS_SUSPECTED_UNADJUSTED = 'suspected_unadjusted';

    public const STATUS_SUSPECTED_ALREADY_ADJUSTED = 'suspected_already_adjusted';

    public const STATUS_NO_PRICES = 'no_prices';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_DEFERRED_TO_FACTOR = 'deferred_to_factor';

    public const STATUS_DUPLICATE_STOCK_EVENT = 'duplicate_stock_event';

    public const STATUS_UNSUPPORTED_ACTION = 'unsupported_action';

    public const STATUS_INVALID_FACTOR = 'invalid_factor';

    public const STATUS_ALREADY_COMPLETED = 'already_completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const SUPPORTED_FACTOR_ACTION_TYPES = ['split', 'bonus', 'face_value_split'];

    public function __construct(
        protected CorporateActionPriceAdjustmentService $priceAdjustment,
        protected MetricsUpdateService $metricsUpdate,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scan(?int $profileId = null, ?int $stockId = null, ?int $actionId = null): array
    {
        $query = CorporateAction::query()
            ->with(['stock', 'profile'])
            ->whereNotNull('applied_at')
            ->orderBy('ex_date')
            ->orderBy('id');

        if ($profileId !== null) {
            $query->where('profile_id', $profileId);
        }
        if ($stockId !== null) {
            $query->where('stock_id', $stockId);
        }
        if ($actionId !== null) {
            $query->where('id', $actionId);
        }

        return $query->get()
            ->map(fn (CorporateAction $action) => $this->inspectAction($action))
            ->values()
            ->all();
    }

    /**
     * Discover F042 pending (and optionally a specific) PriceAdjustmentFactor rows for OHLCV repair.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scanPendingFactors(?int $stockId = null, ?int $factorId = null): array
    {
        $query = PriceAdjustmentFactor::query()
            ->with(['stock', 'issue'])
            ->orderBy('effective_ex_date')
            ->orderBy('id');

        if ($factorId !== null) {
            $query->whereKey($factorId);
        } else {
            $query->pendingOhlcvRepair();
        }

        if ($stockId !== null) {
            $query->where('stock_id', $stockId);
        }

        $factors = $query->get();
        // Ambiguity is global among pending factors (not limited to the current filter).
        $ambiguousKeys = $this->ambiguousPendingStockExDateKeys(
            PriceAdjustmentFactor::query()->pendingOhlcvRepair()->get()
        );

        return $factors
            ->map(fn (PriceAdjustmentFactor $factor) => $this->inspectFactor($factor, $ambiguousKeys))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   scanned: int,
     *   repaired: int,
     *   skipped: int,
     *   details: array<int, array<string, mixed>>
     * }
     */
    public function repair(
        ?int $profileId = null,
        ?int $stockId = null,
        ?int $actionId = null,
        bool $dryRun = true,
        bool $force = false,
    ): array {
        $findings = $this->scan($profileId, $stockId, $actionId);
        $details = [];
        $repaired = 0;
        $skipped = 0;

        foreach ($findings as $finding) {
            $actionIdValue = (int) $finding['corporate_action_id'];
            $status = $finding['status'];
            $repairable = in_array($status, [
                self::STATUS_MISSING_METADATA,
                self::STATUS_SUSPECTED_UNADJUSTED,
            ], true) || ($force && $status === self::STATUS_AMBIGUOUS);

            if (! $repairable) {
                if ($status === self::STATUS_SUSPECTED_ALREADY_ADJUSTED && empty($finding['metadata']['price_adjustment'])) {
                    if ($dryRun) {
                        $details[] = [
                            'corporate_action_id' => $actionIdValue,
                            'action' => 'would_mark_metadata_only',
                            'status' => $status,
                        ];
                        $repaired++;
                        continue;
                    }

                    if ($this->markMetadataOnly($actionIdValue)) {
                        $details[] = [
                            'corporate_action_id' => $actionIdValue,
                            'action' => 'marked_metadata_only',
                            'status' => $status,
                        ];
                        $repaired++;
                    } else {
                        $details[] = [
                            'corporate_action_id' => $actionIdValue,
                            'action' => 'skipped',
                            'status' => self::STATUS_DUPLICATE_STOCK_EVENT,
                            'reason' => 'Stock-event state changed after inspection; metadata was not rewritten.',
                        ];
                        $skipped++;
                    }
                    continue;
                }

                $skipped++;
                $details[] = [
                    'corporate_action_id' => $actionIdValue,
                    'action' => 'skipped',
                    'status' => $status,
                    'reason' => $finding['message'],
                ];
                continue;
            }

            if ($dryRun) {
                $details[] = [
                    'corporate_action_id' => $actionIdValue,
                    'action' => 'would_repair',
                    'status' => $status,
                    'rows_to_adjust' => $finding['rows_before_ex_date'],
                    'price_divisor' => $finding['expected_price_divisor'],
                ];
                $repaired++;
                continue;
            }

            // Resolve the parent key before opening a transaction; this
            // consistent read must not pin a snapshot ahead of the stock lock.
            $candidateStockId = CorporateAction::query()->whereKey($actionIdValue)->value('stock_id');
            if ($candidateStockId === null) {
                $skipped++;
                continue;
            }
            $candidateStockId = (int) $candidateStockId;
            [$stock, $adjustment] = DB::transaction(function () use ($actionIdValue, $candidateStockId): array {
                // Every F020/F042 OHLCV writer takes locks in stock → event order.
                // Resolve the parent ID during the earlier scan, before this
                // transaction begins. Its first database read is the locking
                // read, so REPEATABLE READ cannot pin a pre-lock snapshot.
                $stock = Stock::query()->whereKey($candidateStockId)->lockForUpdate()->first();
                if ($stock === null) return [null, null];
                $action = CorporateAction::query()->whereKey($actionIdValue)->lockForUpdate()->firstOrFail();
                if ((int) $action->stock_id !== (int) $stock->id) {
                    throw new \RuntimeException('Corporate action stock changed while acquiring repair locks.');
                }
                $action->setRelation('stock', $stock);
                $freshFinding = $this->inspectAction($action);
                if (! in_array($freshFinding['status'], [self::STATUS_MISSING_METADATA, self::STATUS_SUSPECTED_UNADJUSTED], true)) {
                    return [$stock, [
                        'rows_adjusted' => 0,
                        '_skipped_status' => $freshFinding['status'],
                    ]];
                }

                $adjustment = $this->priceAdjustment->adjustHistoricalPrices(
                    $stock,
                    $action->ex_date->toDateString(),
                    $action->action_type,
                    (int) $action->ratio_from,
                    (int) $action->ratio_to,
                );
                $invalidatedProofs = $adjustment['_invalidated_proofs'] ?? [];
                unset($adjustment['_invalidated_proofs']);
                $historyEvidence = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
                    ->historyEvidence((int) $stock->id, $action->ex_date->toDateString());

                $action->update([
                    'metadata' => array_merge($action->metadata ?? [], [
                        'price_adjustment' => array_merge($adjustment, [
                            'rows_adjusted' => $historyEvidence['rows_adjusted'],
                            'rows_sha256' => $historyEvidence['rows_sha256'],
                            'repaired_at' => now()->toIso8601String(),
                            'repair_source' => 'portfolio:repair-corporate-action-prices',
                        ]),
                    ]),
                ]);
                app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
                    ->refreshOverlappingRepairProofs($stock, $invalidatedProofs);

                return [$stock, $adjustment];
            });
            if ($stock === null) {
                $skipped++;
                continue;
            }
            if (isset($adjustment['_skipped_status'])) {
                $skipped++;
                $details[] = [
                    'corporate_action_id' => $actionIdValue,
                    'action' => 'skipped',
                    'status' => $adjustment['_skipped_status'],
                    'reason' => 'Stock-event state changed after inspection; no OHLCV write was made.',
                ];
                continue;
            }

            $this->metricsUpdate->updateStock($stock);

            $details[] = [
                'corporate_action_id' => $actionIdValue,
                'action' => 'repaired',
                'status' => $status,
                'rows_adjusted' => $adjustment['rows_adjusted'],
            ];
            $repaired++;
        }

        return [
            'scanned' => count($findings),
            'repaired' => $repaired,
            'skipped' => $skipped,
            'details' => $details,
        ];
    }

    /**
     * Preview / apply F042 PriceAdjustmentFactor OHLCV repairs.
     *
     * Factors are processed in ascending effective_ex_date, then id.
     * Preview (dryRun=true) never mutates OHLCV or factor status.
     *
     * @return array{
     *   scanned: int,
     *   repaired: int,
     *   skipped: int,
     *   details: array<int, array<string, mixed>>
     * }
     */
    public function repairPendingFactors(
        ?int $stockId = null,
        ?int $factorId = null,
        bool $dryRun = true,
        string $repairSource = 'portfolio:repair-corporate-action-prices',
    ): array {
        $findings = $this->scanPendingFactors($stockId, $factorId);
        $details = [];
        $repaired = 0;
        $skipped = 0;

        foreach ($findings as $finding) {
            $factorIdValue = (int) $finding['factor_id'];
            $status = $finding['status'];

            if ($status === self::STATUS_ALREADY_COMPLETED) {
                $skipped++;
                $details[] = [
                    'factor_id' => $factorIdValue,
                    'issue_id' => $finding['issue_id'] ?? null,
                    'action' => 'skipped',
                    'status' => $status,
                    'reason' => $finding['message'],
                ];
                continue;
            }

            $repairable = $status === self::STATUS_PENDING;

            if (! $repairable) {
                $skipped++;
                $details[] = [
                    'factor_id' => $factorIdValue,
                    'issue_id' => $finding['issue_id'] ?? null,
                    'action' => 'skipped',
                    'status' => $status,
                    'reason' => $finding['message'],
                    'errors' => $finding['errors'] ?? [],
                ];
                continue;
            }

            if ($dryRun) {
                $details[] = [
                    'factor_id' => $factorIdValue,
                    'issue_id' => $finding['issue_id'] ?? null,
                    'stock_id' => $finding['stock_id'],
                    'symbol' => $finding['symbol'] ?? null,
                    'action' => 'would_repair',
                    'status' => $status,
                    'action_type' => $finding['action_type'],
                    'ex_date' => $finding['ex_date'],
                    'rows_to_adjust' => $finding['rows_to_adjust'],
                    'price_divisor' => $finding['price_divisor'],
                    'volume_multiplier' => $finding['volume_multiplier'],
                    'samples' => $finding['samples'] ?? [],
                    'ohlcv_repair_status' => $finding['ohlcv_repair_status'],
                    'already_completed' => false,
                ];
                $repaired++;
                continue;
            }

            try {
                $result = $this->applyFactorRepair($factorIdValue, $repairSource);
            } catch (\Throwable $e) {
                $skipped++;
                $details[] = [
                    'factor_id' => $factorIdValue,
                    'issue_id' => $finding['issue_id'] ?? null,
                    'action' => 'failed',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            if (($result['action'] ?? null) === 'repaired') {
                $repaired++;
            } else {
                $skipped++;
            }
            $details[] = $result;
        }

        return [
            'scanned' => count($findings),
            'repaired' => $repaired,
            'skipped' => $skipped,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectAction(CorporateAction $action): array
    {
        $stock = $action->stock;
        $exDate = $action->ex_date->toDateString();
        $metadata = $action->metadata ?? [];
        $priceMeta = $metadata['price_adjustment'] ?? null;
        $rowsBefore = $stock
            ? $this->priceAdjustment->previewAdjustment(
                $stock,
                $exDate,
                $action->action_type,
                (int) $action->ratio_from,
                (int) $action->ratio_to,
            )['rows_to_adjust']
            : 0;

        $expectedDivisor = $this->priceAdjustment->factorsForAction(
            $action->action_type,
            (int) $action->ratio_from,
            (int) $action->ratio_to,
        )['price_divisor'];

        $eventTypes = in_array($action->action_type, ['split', 'face_value_split'], true)
            ? ['split', 'face_value_split']
            : [$action->action_type];
        $stockEvents = CorporateAction::query()->where('stock_id', $action->stock_id)
            ->whereIn('action_type', $eventTypes)->whereDate('ex_date', $exDate)
            ->whereNotNull('applied_at')->orderBy('id')->get();
        foreach ($stockEvents as $stockEvent) {
            if ((int) $stockEvent->ratio_from !== (int) $action->ratio_from
                || (int) $stockEvent->ratio_to !== (int) $action->ratio_to) {
                return $this->finding($action, self::STATUS_DUPLICATE_STOCK_EVENT,
                    'Conflicting ratios exist for this stock-global corporate-action event; manual resolution is required.', [
                        'rows_before_ex_date' => $rowsBefore,
                        'expected_price_divisor' => $expectedDivisor,
                        'errors' => ['conflicting_stock_event_ratio'],
                    ]);
            }
        }

        $adjustedEvents = $stockEvents->filter(function (CorporateAction $candidate): bool {
            $adjustment = ($candidate->metadata ?? [])['price_adjustment'] ?? [];
            return is_array($adjustment)
                && ! isset($adjustment['repair_note'])
                && (int) ($adjustment['rows_adjusted'] ?? 0) > 0;
        })->values();
        if ($adjustedEvents->count() > 1) {
            return $this->finding($action, self::STATUS_DUPLICATE_STOCK_EVENT,
                'Multiple F020 records claim an OHLCV restatement for this stock event; refusing another adjustment.', [
                    'rows_before_ex_date' => $rowsBefore,
                    'expected_price_divisor' => $expectedDivisor,
                    'errors' => ['multiple_stock_event_restatements'],
                ]);
        }
        if ($adjustedEvents->isNotEmpty()) {
            /** @var CorporateAction $restatementOwner */
            $restatementOwner = $adjustedEvents->first();
            $gate = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class);
            if ($gate->restatementTouchesProtectedHistory($stock)
                && ! $gate->hasCurrentCorporateActionProof($restatementOwner)) {
                return $this->finding($action, self::STATUS_DUPLICATE_STOCK_EVENT,
                    'The existing stock-event restatement proof is stale or incomplete; refusing to trust or repeat it.', [
                        'rows_before_ex_date' => $rowsBefore,
                        'expected_price_divisor' => $expectedDivisor,
                        'errors' => ['stale_stock_event_restatement_proof'],
                    ]);
            }
            if ((int) $restatementOwner->id !== (int) $action->id) {
                return $this->finding($action, self::STATUS_SUSPECTED_ALREADY_ADJUSTED,
                    'Another portfolio already owns the stock-global OHLCV restatement for this event.', [
                        'rows_before_ex_date' => $rowsBefore,
                        'expected_price_divisor' => $expectedDivisor,
                        'rows_adjusted' => (int) (($restatementOwner->metadata ?? [])['price_adjustment']['rows_adjusted'] ?? 0),
                    ]);
            }
        }
        if ($stockEvents->count() > 1
            && (int) $stockEvents->first()->id !== (int) $action->id
            && $adjustedEvents->isEmpty()) {
            return $this->finding($action, self::STATUS_DUPLICATE_STOCK_EVENT,
                'Only the earliest stock-global action record may own legacy OHLCV repair for this event.', [
                    'rows_before_ex_date' => $rowsBefore,
                    'expected_price_divisor' => $expectedDivisor,
                    'errors' => ['noncanonical_stock_event_restatement'],
                ]);
        }

        if ($stock !== null && $this->activeFactorExistsForStockExDate((int) $stock->id, $exDate, $action->action_type)) {
            return $this->finding($action, self::STATUS_DEFERRED_TO_FACTOR, 'Active F042 PriceAdjustmentFactor exists for this stock/ex-date/action; use factor repair path to avoid double adjustment.', [
                'rows_before_ex_date' => $rowsBefore,
                'expected_price_divisor' => $expectedDivisor,
            ]);
        }

        if ($rowsBefore === 0) {
            return $this->finding($action, self::STATUS_NO_PRICES, 'No cached OHLCV rows before ex-date.', [
                'rows_before_ex_date' => 0,
                'expected_price_divisor' => $expectedDivisor,
            ]);
        }

        if (is_array($priceMeta) && (int) ($priceMeta['rows_adjusted'] ?? 0) > 0) {
            return $this->finding($action, self::STATUS_OK, 'Price cache already restated for this corporate action.', [
                'rows_before_ex_date' => $rowsBefore,
                'expected_price_divisor' => $expectedDivisor,
                'rows_adjusted' => (int) $priceMeta['rows_adjusted'],
            ]);
        }

        $continuity = $stock ? $this->continuityRatio($stock, $exDate) : null;

        if ($continuity === null) {
            return $this->finding($action, self::STATUS_MISSING_METADATA, 'OHLCV exists but ex-date boundary is incomplete; metadata missing.', [
                'rows_before_ex_date' => $rowsBefore,
                'expected_price_divisor' => $expectedDivisor,
            ]);
        }

        if ($this->ratioNear($continuity, $expectedDivisor)) {
            return $this->finding($action, self::STATUS_SUSPECTED_UNADJUSTED, sprintf(
                'Chart gap detected: last pre-ex close ÷ first post-ex close ≈ %.2f (expected ÷%.2f).',
                $continuity,
                $expectedDivisor,
            ), [
                'rows_before_ex_date' => $rowsBefore,
                'expected_price_divisor' => $expectedDivisor,
                'continuity_ratio' => round($continuity, 4),
            ]);
        }

        if ($this->ratioNear($continuity, 1.0)) {
            return $this->finding($action, self::STATUS_SUSPECTED_ALREADY_ADJUSTED, sprintf(
                'Prices look continuous across ex-date (ratio ≈ %.2f) but repair metadata is missing.',
                $continuity,
            ), [
                'rows_before_ex_date' => $rowsBefore,
                'expected_price_divisor' => $expectedDivisor,
                'continuity_ratio' => round($continuity, 4),
            ]);
        }

        return $this->finding($action, self::STATUS_AMBIGUOUS, sprintf(
            'Could not confidently classify OHLCV (continuity ratio %.2f vs expected ÷%.2f or ÷1).',
            $continuity,
            $expectedDivisor,
        ), [
            'rows_before_ex_date' => $rowsBefore,
            'expected_price_divisor' => $expectedDivisor,
            'continuity_ratio' => round($continuity, 4),
        ]);
    }

    /**
     * @param  array<string, true>  $ambiguousKeys
     * @return array<string, mixed>
     */
    public function inspectFactor(PriceAdjustmentFactor $factor, array $ambiguousKeys = []): array
    {
        $stock = $factor->stock;
        $metadata = $factor->metadata ?? [];
        $repairStatus = (string) ($metadata['ohlcv_repair_status'] ?? '');
        $exDate = $factor->effective_ex_date?->toDateString();
        $actionType = strtolower((string) ($factor->action_type ?? ''));
        $priceDivisor = (float) $factor->price_divisor;
        $volumeMultiplier = (float) $factor->volume_multiplier;
        $errors = [];

        $base = [
            'factor_id' => $factor->id,
            'issue_id' => $factor->issue_id,
            'stock_id' => $factor->stock_id,
            'symbol' => $stock?->symbol,
            'action_type' => $factor->action_type,
            'ex_date' => $exDate,
            'applied_ratio' => (float) $factor->applied_ratio,
            'price_divisor' => $priceDivisor,
            'volume_multiplier' => $volumeMultiplier,
            'is_active' => (bool) $factor->is_active,
            'ohlcv_repair_status' => $repairStatus !== '' ? $repairStatus : null,
            'already_completed' => $repairStatus === PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED,
            'errors' => [],
            'warnings' => [],
            'samples' => [],
            'rows_to_adjust' => 0,
            'affected_date_range' => null,
        ];

        if (! $factor->is_active) {
            return array_merge($base, [
                'status' => self::STATUS_INACTIVE,
                'message' => 'Factor is inactive; OHLCV repair skipped.',
            ]);
        }

        if ($repairStatus === PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED) {
            return array_merge($base, [
                'status' => self::STATUS_ALREADY_COMPLETED,
                'message' => 'Factor OHLCV repair already completed; idempotent skip.',
            ]);
        }

        if ($repairStatus !== PriceAdjustmentFactor::REPAIR_STATUS_PENDING) {
            $errors[] = 'ohlcv_repair_status must be pending (got: '.($repairStatus !== '' ? $repairStatus : 'null').').';
        }

        if ($stock === null) {
            $errors[] = 'Factor has no stock attached.';
        }

        if ($exDate === null || $exDate === '') {
            $errors[] = 'effective_ex_date is required.';
        }

        if ($priceDivisor <= 0) {
            $errors[] = 'price_divisor must be > 0.';
        }

        if ($volumeMultiplier <= 0) {
            $errors[] = 'volume_multiplier must be > 0.';
        }

        if ($actionType === '' || ! in_array($actionType, self::SUPPORTED_FACTOR_ACTION_TYPES, true)) {
            return array_merge($base, [
                'status' => self::STATUS_UNSUPPORTED_ACTION,
                'message' => sprintf(
                    'Unsupported corporate-action type for F043 factor repair: %s.',
                    $factor->action_type ?? 'null',
                ),
                'errors' => array_merge($errors, ['unsupported_action_type']),
            ]);
        }

        $key = $this->stockExDateKey((int) $factor->stock_id, (string) $exDate);
        if ($exDate !== null && isset($ambiguousKeys[$key])) {
            return array_merge($base, [
                'status' => self::STATUS_AMBIGUOUS,
                'message' => 'Multiple pending factors share the same stock and effective_ex_date; resolve manually (no silent merge).',
                'errors' => ['overlapping_pending_factors'],
            ]);
        }

        if ($errors !== []) {
            return array_merge($base, [
                'status' => self::STATUS_INVALID_FACTOR,
                'message' => 'Factor failed validation.',
                'errors' => $errors,
            ]);
        }

        $preview = $this->priceAdjustment->previewAdjustmentByDivisors(
            $stock,
            $exDate,
            $priceDivisor,
            $volumeMultiplier,
        );

        if ($preview['rows_to_adjust'] === 0) {
            return array_merge($base, [
                'status' => self::STATUS_NO_PRICES,
                'message' => 'No cached OHLCV rows before effective_ex_date; cannot complete repair yet.',
                'rows_to_adjust' => 0,
                'price_divisor' => $priceDivisor,
                'volume_multiplier' => $volumeMultiplier,
            ]);
        }

        $existingActions = CorporateAction::query()->where('stock_id', $factor->stock_id)
            ->whereDate('ex_date', $exDate)
            ->whereIn('action_type', ['split', 'face_value_split'])
            ->whereNotNull('applied_at')->orderBy('id')->get();
        foreach ($existingActions as $existingAction) {
            $expectedAction = $this->priceAdjustment->factorsForAction(
                $existingAction->action_type,
                (int) $existingAction->ratio_from,
                (int) $existingAction->ratio_to,
            );
            if (abs($expectedAction['price_divisor'] - $priceDivisor) > 0.000001
                || abs($expectedAction['volume_multiplier'] - $volumeMultiplier) > 0.000001) {
                return array_merge($base, [
                    'status' => self::STATUS_DUPLICATE_STOCK_EVENT,
                    'message' => 'F020 and F042 ratios conflict for this stock-global event; manual resolution is required.',
                    'errors' => ['conflicting_f020_f042_ratio'],
                ]);
            }
        }
        $restatementOwners = $existingActions->filter(function (CorporateAction $candidate): bool {
            $candidateAdjustment = ($candidate->metadata ?? [])['price_adjustment'] ?? [];
            return is_array($candidateAdjustment)
                && ! isset($candidateAdjustment['repair_note'])
                && (int) ($candidateAdjustment['rows_adjusted'] ?? 0) > 0;
        })->values();
        if ($restatementOwners->count() > 1) {
            return array_merge($base, [
                'status' => self::STATUS_DUPLICATE_STOCK_EVENT,
                'message' => 'Multiple F020 records claim this stock-global restatement; refusing F042 repair.',
                'errors' => ['multiple_f020_restatement_owners'],
            ]);
        }
        if ($restatementOwners->isNotEmpty()) {
            $owner = $restatementOwners->first();
            if (app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
                ->restatementTouchesProtectedHistory($stock)
                && ! app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
                    ->hasCurrentCorporateActionProof($owner)) {
                return array_merge($base, [
                    'status' => self::STATUS_DUPLICATE_STOCK_EVENT,
                    'message' => 'F020 restatement proof is stale or incomplete; refusing to trust or repeat it.',
                    'errors' => ['stale_f020_restatement_proof'],
                ]);
            }
            return array_merge($base, [
                'status' => self::STATUS_DEFERRED_TO_FACTOR,
                'message' => 'F020 already restated this stock/ex-date; refusing a second F042 adjustment.',
                'errors' => ['duplicate_action_restatement'],
            ]);
        }

        if ($existingActions->isNotEmpty()) {
            $continuity = $this->continuityRatio($stock, $exDate);
            if ($continuity === null || ! $this->ratioNear($continuity, $priceDivisor)) {
                return array_merge($base, [
                    'status' => self::STATUS_AMBIGUOUS,
                    'message' => 'An applied F020 action exists without matching raw-price evidence; refusing possible double adjustment.',
                    'errors' => ['duplicate_action_price_state_ambiguous'],
                ]);
            }
        }

        $completedFactor = PriceAdjustmentFactor::query()->where('stock_id', $factor->stock_id)
            ->whereDate('effective_ex_date', $exDate)
            ->where('id', '!=', $factor->id)
            ->where('is_active', true)
            ->get(['id', 'metadata'])
            ->first(fn (PriceAdjustmentFactor $candidate): bool =>
                (($candidate->metadata ?? [])['ohlcv_repair_status'] ?? null) === PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED
            );
        if ($completedFactor !== null) {
            return array_merge($base, [
                'status' => self::STATUS_DEFERRED_TO_FACTOR,
                'message' => 'A completed F042 factor already restated this stock/ex-date; refusing a second adjustment.',
                'errors' => ['duplicate_factor_restatement'],
            ]);
        }

        $samples = $this->priceAdjustment->sampleAdjustmentPreview(
            $stock,
            $exDate,
            $priceDivisor,
            $volumeMultiplier,
            3,
        );

        $oldest = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '<', $exDate)
            ->orderBy('price_date')
            ->value('price_date');

        return array_merge($base, [
            'status' => self::STATUS_PENDING,
            'message' => 'Pending F042 factor ready for OHLCV repair.',
            'rows_to_adjust' => $preview['rows_to_adjust'],
            'price_divisor' => $priceDivisor,
            'volume_multiplier' => $volumeMultiplier,
            'samples' => $samples,
            'affected_date_range' => [
                'from' => $oldest ? (string) $oldest : null,
                'before' => $exDate,
            ],
            'warnings' => [
                'F042 acceptance already unblocked pipelines; OHLCV may still be unrestated until apply.',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function applyFactorRepair(int $factorId, string $repairSource): array
    {
        // This lookup must stay outside the transaction: a consistent read
        // inside it could establish a stale REPEATABLE READ snapshot before
        // waiting on the stock lock.
        $candidateStockId = PriceAdjustmentFactor::query()->whereKey($factorId)->value('stock_id');
        if ($candidateStockId === null) {
            return [
                'factor_id' => $factorId,
                'action' => 'skipped',
                'status' => self::STATUS_INVALID_FACTOR,
                'reason' => 'Factor not found.',
            ];
        }

        return DB::transaction(function () use ($factorId, $repairSource, $candidateStockId) {
            /** @var PriceAdjustmentFactor|null $factor */
            // Keep writer lock order stock → factor, matching direct and repair F020.
            $stock = Stock::query()->whereKey($candidateStockId)->lockForUpdate()->first();
            if ($stock === null) {
                return [
                    'factor_id' => $factorId,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor stock no longer exists.',
                ];
            }
            $factor = PriceAdjustmentFactor::query()->with('issue')->whereKey($factorId)->lockForUpdate()->first();

            if ($factor === null) {
                return [
                    'factor_id' => $factorId,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor not found.',
                ];
            }

            if ((int) $factor->stock_id !== (int) $candidateStockId) {
                return [
                    'factor_id' => $factor->id,
                    'issue_id' => $factor->issue_id,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor stock changed while acquiring repair locks.',
                ];
            }
            $factor->setRelation('stock', $stock);

            $ambiguousKeys = $this->ambiguousPendingStockExDateKeys(
                PriceAdjustmentFactor::query()->pendingOhlcvRepair()->get()
            );
            $inspection = $this->inspectFactor($factor, $ambiguousKeys);

            if ($inspection['status'] === self::STATUS_ALREADY_COMPLETED) {
                return [
                    'factor_id' => $factor->id,
                    'issue_id' => $factor->issue_id,
                    'action' => 'skipped',
                    'status' => self::STATUS_ALREADY_COMPLETED,
                    'reason' => $inspection['message'],
                ];
            }

            if ($inspection['status'] !== self::STATUS_PENDING) {
                return [
                    'factor_id' => $factor->id,
                    'issue_id' => $factor->issue_id,
                    'action' => 'skipped',
                    'status' => $inspection['status'],
                    'reason' => $inspection['message'],
                    'errors' => $inspection['errors'] ?? [],
                ];
            }

            $stock = $factor->stock;
            $exDate = $factor->effective_ex_date->toDateString();
            $previousStatus = (string) (($factor->metadata ?? [])['ohlcv_repair_status'] ?? PriceAdjustmentFactor::REPAIR_STATUS_PENDING);

            $adjustment = $this->priceAdjustment->adjustHistoricalPricesByDivisors(
                $stock,
                $exDate,
                (float) $factor->price_divisor,
                (float) $factor->volume_multiplier,
            );

            if ((int) $adjustment['rows_adjusted'] < 1) {
                // Leave pending — no successful mutation to audit as completed.
                return [
                    'factor_id' => $factor->id,
                    'issue_id' => $factor->issue_id,
                    'action' => 'skipped',
                    'status' => self::STATUS_NO_PRICES,
                    'reason' => 'Adjuster updated zero rows; factor left pending.',
                ];
            }

            $metadata = $factor->metadata ?? [];
            $invalidatedProofs = $adjustment['_invalidated_proofs'] ?? [];
            unset($adjustment['_invalidated_proofs']);
            $metadata['ohlcv_repair_status'] = PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED;
            $metadata['ohlcv_repair'] = [
                'previous_status' => $previousStatus,
                'resulting_status' => PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED,
                'rows_adjusted' => $adjustment['rows_adjusted'],
                'rows_sha256' => app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
                    ->historyEvidence((int) $factor->stock_id, $exDate)['rows_sha256'],
                'price_divisor' => $adjustment['price_divisor'],
                'volume_multiplier' => $adjustment['volume_multiplier'],
                'adjusted_before_date' => $adjustment['adjusted_before_date'],
                'affected_date_range' => $inspection['affected_date_range'] ?? null,
                'stock_id' => $factor->stock_id,
                'issue_id' => $factor->issue_id,
                'action_type' => $factor->action_type,
                'repaired_at' => now()->toIso8601String(),
                'repair_source' => $repairSource,
            ];

            $factor->update(['metadata' => $metadata]);
            app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
                ->refreshOverlappingRepairProofs($stock, $invalidatedProofs);

            $this->metricsUpdate->updateStock($stock);

            return [
                'factor_id' => $factor->id,
                'issue_id' => $factor->issue_id,
                'stock_id' => $factor->stock_id,
                'action' => 'repaired',
                'status' => self::STATUS_OK,
                'rows_adjusted' => $adjustment['rows_adjusted'],
                'price_divisor' => $adjustment['price_divisor'],
                'volume_multiplier' => $adjustment['volume_multiplier'],
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED,
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PriceAdjustmentFactor>  $factors
     * @return array<string, true>
     */
    protected function ambiguousPendingStockExDateKeys($factors): array
    {
        $counts = [];
        foreach ($factors as $factor) {
            if (! $factor->is_active) {
                continue;
            }
            $status = (string) (($factor->metadata ?? [])['ohlcv_repair_status'] ?? '');
            if ($status !== PriceAdjustmentFactor::REPAIR_STATUS_PENDING) {
                continue;
            }
            $exDate = $factor->effective_ex_date?->toDateString();
            if ($exDate === null) {
                continue;
            }
            $key = $this->stockExDateKey((int) $factor->stock_id, $exDate);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $ambiguous = [];
        foreach ($counts as $key => $count) {
            if ($count > 1) {
                $ambiguous[$key] = true;
            }
        }

        return $ambiguous;
    }

    protected function activeFactorExistsForStockExDate(int $stockId, string $exDate, ?string $actionType = null): bool
    {
        return PriceAdjustmentFactor::query()
            ->activeOhlcvRepairForEvent($stockId, $exDate, $actionType)
            ->exists();
    }

    protected function stockExDateKey(int $stockId, string $exDate): string
    {
        return $stockId.'|'.$exDate;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function finding(CorporateAction $action, string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'corporate_action_id' => $action->id,
            'profile_id' => $action->profile_id,
            'stock_id' => $action->stock_id,
            'symbol' => $action->stock?->symbol,
            'action_type' => $action->action_type,
            'ratio' => $action->ratio_from.':'.$action->ratio_to,
            'ex_date' => $action->ex_date->toDateString(),
            'status' => $status,
            'message' => $message,
            'metadata' => $action->metadata ?? [],
        ], $extra);
    }

    protected function continuityRatio(Stock $stock, string $exDate): ?float
    {
        $beforeClose = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '<', $exDate)
            ->orderByDesc('price_date')
            ->value('close_price');

        $afterClose = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $exDate)
            ->orderBy('price_date')
            ->value('close_price');

        if ($beforeClose === null || $afterClose === null || (float) $afterClose <= 0) {
            return null;
        }

        return (float) $beforeClose / (float) $afterClose;
    }

    protected function ratioNear(float $actual, float $expected, float $tolerance = 0.25): bool
    {
        if ($expected <= 0) {
            return false;
        }

        return abs($actual - $expected) / $expected <= $tolerance;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    protected function markMetadataOnly(int $actionId): bool
    {
        // Resolve the parent key before opening the transaction. Inside it,
        // take the shared stock lock before any consistent inspection reads.
        $candidateStockId = CorporateAction::query()->whereKey($actionId)->value('stock_id');
        if ($candidateStockId === null) return false;

        return DB::transaction(function () use ($actionId, $candidateStockId): bool {
            $stock = Stock::query()->whereKey($candidateStockId)->lockForUpdate()->first();
            if ($stock === null) return false;
            $action = CorporateAction::query()->whereKey($actionId)->lockForUpdate()->first();
            if ($action === null || (int) $action->stock_id !== (int) $candidateStockId) return false;
            $action->setRelation('stock', $stock);
            $fresh = $this->inspectAction($action);
            if ($fresh['status'] !== self::STATUS_SUSPECTED_ALREADY_ADJUSTED
                || ! empty(($action->metadata ?? [])['price_adjustment'])) return false;

            $preview = $this->priceAdjustment->previewAdjustment(
                $stock,
                $action->ex_date->toDateString(),
                $action->action_type,
                (int) $action->ratio_from,
                (int) $action->ratio_to,
            );
            $action->update([
                'metadata' => array_merge($action->metadata ?? [], [
                    'price_adjustment' => array_merge($preview, [
                        'rows_adjusted' => 0,
                        'repaired_at' => now()->toIso8601String(),
                        'repair_source' => 'portfolio:repair-corporate-action-prices',
                        'repair_note' => 'metadata_only_prices_already_continuous',
                    ]),
                ]),
            ]);

            return true;
        });
    }
}
