<?php

namespace App\Services;

use App\Models\CorporateAction;
use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueResolution;
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
        ?string $expectedPreflightSha256 = null,
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

            if ($expectedPreflightSha256 !== null
                && (! preg_match('/^[a-f0-9]{64}$/', $expectedPreflightSha256)
                    || ! isset($finding['preflight_sha256'])
                    || ! hash_equals($expectedPreflightSha256, $finding['preflight_sha256']))) {
                $skipped++;
                $details[] = [
                    'factor_id' => $factorIdValue,
                    'issue_id' => $finding['issue_id'] ?? null,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'The supplied preflight fingerprint does not match the current factor and affected price rows.',
                    'preflight_sha256' => $finding['preflight_sha256'] ?? null,
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
                    'preflight_sha256' => $finding['preflight_sha256'] ?? null,
                    'protected_rows_sha256' => $finding['protected_rows_sha256'] ?? null,
                    'already_completed' => false,
                ];
                $repaired++;
                continue;
            }

            try {
                // A broad run may have already applied an earlier factor whose
                // date overlaps this factor's row set. Refresh this factor's
                // baseline after that committed change; explicit preflight
                // fingerprints remain valid only for their one exact factor.
                $applyFinding = $finding;
                if ($expectedPreflightSha256 === null) {
                    $currentFinding = $this->scanPendingFactors($stockId, $factorIdValue)[0] ?? null;
                    if ($currentFinding !== null) {
                        $applyFinding = $currentFinding;
                    }
                }
                if (($applyFinding['status'] ?? null) !== self::STATUS_PENDING) {
                    $skipped++;
                    $details[] = [
                        'factor_id' => $factorIdValue,
                        'issue_id' => $finding['issue_id'] ?? null,
                        'action' => 'skipped',
                        'status' => $applyFinding['status'] ?? self::STATUS_INVALID_FACTOR,
                        'reason' => $applyFinding['message'] ?? 'Factor is no longer eligible after a preceding repair.',
                    ];
                    continue;
                }
                $result = $this->applyFactorRepair($factorIdValue, $repairSource, (string) ($applyFinding['preflight_sha256'] ?? ''));
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

        $appliedRatio = (float) $factor->applied_ratio;
        // Newly approved F042 factors must retain the formula bound to the
        // accepted ratio. Only explicitly tagged legacy factors without an
        // approval link may use historical stored-divisor semantics.
        if ($appliedRatio <= 0 || ! is_finite($appliedRatio)
            || ! is_finite($priceDivisor) || ! is_finite($volumeMultiplier)) {
            return array_merge($base, [
                'status' => self::STATUS_INVALID_FACTOR,
                'message' => 'Stored F042 ratio and divisors must be finite positive values.',
                'errors' => ['factor_non_finite_values'],
            ]);
        }

        if ($factor->issue_id !== null) {
            $issue = $factor->issue;
            $approvedDivisor = $actionType === 'bonus' ? 1 + $appliedRatio : $appliedRatio;
            $approvalResolution = $issue?->linkedResolution;
            $resolutionMismatch = $issue?->latest_resolution_id !== null
                && ($approvalResolution === null
                    || (int) $approvalResolution->issue_id !== (int) $issue->id
                    || $approvalResolution->resolution_status !== DataQualityIssue::STATUS_ACCEPTED
                    || ! in_array($approvalResolution->resolution_type, [
                        DataQualityIssueResolution::TYPE_ACCEPTED,
                        DataQualityIssueResolution::TYPE_MODIFIED_ACCEPTED,
                        DataQualityIssueResolution::TYPE_AUTO_ACCEPTED,
                        DataQualityIssueResolution::TYPE_MIGRATED,
                    ], true)
                    || abs((float) $approvalResolution->applied_ratio - $appliedRatio) > 0.000001);
            if ($issue === null || $issue->issue_type !== DataQualityIssue::TYPE_CORPORATE_ACTION
                || $issue->issue_status !== DataQualityIssue::STATUS_ACCEPTED
                || (int) $issue->stock_id !== (int) $factor->stock_id
                || $issue->ex_date?->toDateString() !== $exDate
                || strtolower((string) $issue->corporate_action_type) !== $actionType
                || abs((float) $issue->applied_ratio - $appliedRatio) > 0.000001
                || abs($priceDivisor - $approvedDivisor) > 0.000001
                || abs($volumeMultiplier - $approvedDivisor) > 0.000001
                || $resolutionMismatch) {
                return array_merge($base, [
                    'status' => self::STATUS_INVALID_FACTOR,
                    'message' => 'Linked Data Quality approval or its approved factors no longer match this F042 factor.',
                    'errors' => ['factor_approval_binding_mismatch'],
                ]);
            }
        } elseif (($metadata['divisor_provenance'] ?? null) !== 'legacy_stored_factors') {
            return array_merge($base, [
                'status' => self::STATUS_INVALID_FACTOR,
                'message' => 'Unlinked factor lacks explicit legacy stored-divisor provenance.',
                'errors' => ['legacy_divisor_provenance_missing'],
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
            ->whereIn('action_type', ['split', 'face_value_split', 'bonus'])
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
            ...$this->factorPreflightEvidence($factor, $exDate),
            'warnings' => [
                'F042 acceptance already unblocked pipelines; OHLCV may still be unrestated until apply.',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function applyFactorRepair(int $factorId, string $repairSource, string $expectedPreflightSha256): array
    {
        // This lookup must stay outside the transaction: a consistent read
        // inside it could establish a stale REPEATABLE READ snapshot before
        // waiting on the stock lock.
        $candidate = PriceAdjustmentFactor::query()->whereKey($factorId)->first(['stock_id', 'issue_id']);
        if ($candidate === null) {
            return [
                'factor_id' => $factorId,
                'action' => 'skipped',
                'status' => self::STATUS_INVALID_FACTOR,
                'reason' => 'Factor not found.',
            ];
        }

        return DB::transaction(function () use ($factorId, $repairSource, $candidate, $expectedPreflightSha256) {
            /** @var PriceAdjustmentFactor|null $factor */
            // All F020/F042 writers serialize by stock first, then issue and
            // factor. Locking issue first here can deadlock with a writer that
            // already owns the stock row and then resolves the same issue.
            $stock = Stock::query()->whereKey($candidate->stock_id)->lockForUpdate()->first();
            if ($stock === null) {
                return [
                    'factor_id' => $factorId,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor stock no longer exists.',
                ];
            }
            $lockedIssue = null;
            if ($candidate->issue_id !== null) {
                $lockedIssue = DataQualityIssue::query()->whereKey($candidate->issue_id)->lockForUpdate()->first();
            }
            if ($lockedIssue !== null && (int) $lockedIssue->stock_id !== (int) $stock->id) {
                return [
                    'factor_id' => $factorId,
                    'issue_id' => $lockedIssue->id,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor issue stock changed while acquiring repair locks.',
                ];
            }
            $factor = PriceAdjustmentFactor::query()->whereKey($factorId)->lockForUpdate()->first();

            if ($factor === null) {
                return [
                    'factor_id' => $factorId,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor not found.',
                ];
            }
            // Lock the current linked resolution and every protected price row before any
            // consistent read establishes a MariaDB REPEATABLE READ snapshot.
            // This fences both approval changes and direct price writers that
            // do not participate in the stock-row mutex convention.
            if ((int) ($factor->issue_id ?? 0) !== (int) ($candidate->issue_id ?? 0)) {
                return [
                    'factor_id' => $factor->id,
                    'issue_id' => $factor->issue_id,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor approval link changed while acquiring repair locks.',
                ];
            }
            if ($lockedIssue !== null && $lockedIssue->latest_resolution_id !== null) {
                DataQualityIssueResolution::query()->whereKey($lockedIssue->latest_resolution_id)->lockForUpdate()->first();
            }
            $factor->setRelation('issue', $lockedIssue);
            if ($factor->effective_ex_date !== null) {
                DB::table('portfolio_stock_prices')->where('stock_id', $factor->stock_id)
                    ->where('price_date', '<', $factor->effective_ex_date->toDateString())
                    ->orderBy('id')->lockForUpdate()->get(['id']);
            }

            if ((int) $factor->stock_id !== (int) $candidate->stock_id) {
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

            if ($expectedPreflightSha256 === ''
                || ! hash_equals($expectedPreflightSha256, (string) ($inspection['preflight_sha256'] ?? ''))) {
                return [
                    'factor_id' => $factor->id,
                    'issue_id' => $factor->issue_id,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor or affected price rows changed after preview; generate a new preflight before applying.',
                ];
            }

            $stock = $factor->stock;
            $exDate = $factor->effective_ex_date->toDateString();
            $previousStatus = (string) (($factor->metadata ?? [])['ohlcv_repair_status'] ?? PriceAdjustmentFactor::REPAIR_STATUS_PENDING);

            $beforeRows = DB::table('portfolio_stock_prices')->where('stock_id', $stock->id)
                ->where('price_date', '<', $exDate)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            if ($beforeRows === []) {
                return ['factor_id' => $factor->id, 'issue_id' => $factor->issue_id, 'action' => 'skipped', 'status' => self::STATUS_NO_PRICES, 'reason' => 'No rows to snapshot.'];
            }
            // Immutable exact row image; no inverse arithmetic is used during rollback.
            foreach ($beforeRows as $row) {
                DB::table('portfolio_price_adjustment_repair_snapshots')->insert([
                    'factor_id' => $factor->id, 'stock_price_id' => $row['id'],
                    'before_row' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $adjustment = $this->priceAdjustment->adjustHistoricalPricesByDivisors(
                $stock,
                $exDate,
                (float) $factor->price_divisor,
                (float) $factor->volume_multiplier,
            );

            if ((int) $adjustment['rows_adjusted'] < 1) {
                DB::table('portfolio_price_adjustment_repair_snapshots')->where('factor_id', $factor->id)->delete();
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
            $afterRows = DB::table('portfolio_stock_prices')->whereIn('id', array_column($beforeRows, 'id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            if (count($afterRows) !== count($beforeRows) || array_column($afterRows, 'id') !== array_column($beforeRows, 'id')) {
                throw new \RuntimeException('Exact post-repair row set differs from the locked pre-repair row set.');
            }
            foreach ($afterRows as $row) {
                DB::table('portfolio_price_adjustment_repair_snapshots')->where('factor_id', $factor->id)->where('stock_price_id', $row['id'])->update([
                    'after_row' => json_encode($row, JSON_THROW_ON_ERROR), 'after_sha256' => $this->rowHash($row), 'updated_at' => now(),
                ]);
            }
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
                'snapshot_version' => 1,
                'snapshot_rows' => count($beforeRows),
                'snapshot_before_sha256' => hash('sha256', implode('', array_map(fn ($r) => $this->rowHash($r), $beforeRows))),
                'snapshot_sha256' => hash('sha256', implode('', array_map(fn ($r) => $this->rowHash($r), $afterRows))),
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
        }, 3);
    }

    /** Restore exact captured rows. Caller holds stock → issue → factor locks and transaction. */
    public function restoreCompletedFactor(PriceAdjustmentFactor $factor): array
    {
        $meta = $factor->metadata ?? [];
        if (($meta['ohlcv_repair_status'] ?? null) !== PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED
            || (int) (($meta['ohlcv_repair']['snapshot_version'] ?? 0)) !== 1) {
            throw new \App\Exceptions\DomainException('Completed factor has no supported exact row snapshot.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
        }
        $snapshots = DB::table('portfolio_price_adjustment_repair_snapshots')->where('factor_id', $factor->id)->orderBy('stock_price_id')->lockForUpdate()->get();
        if ($snapshots->isEmpty() || $snapshots->count() !== (int) ($meta['ohlcv_repair']['snapshot_rows'] ?? -1)
            || $snapshots->contains(fn ($s) => $s->after_row === null || $s->after_sha256 === null)) {
            throw new \App\Exceptions\DomainException('Repair snapshot is incomplete.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
        }
        $beforeHashes = [];
        $afterHashes = [];
        foreach ($snapshots as $snapshot) {
            $beforeRow = json_decode($snapshot->before_row, true, flags: JSON_THROW_ON_ERROR);
            $afterRow = json_decode($snapshot->after_row, true, flags: JSON_THROW_ON_ERROR);
            $beforeHashes[] = $this->rowHash($beforeRow);
            $afterHashes[] = $this->rowHash($afterRow);
        }
        if (! hash_equals((string) ($meta['ohlcv_repair']['snapshot_before_sha256'] ?? ''), hash('sha256', implode('', $beforeHashes)))
            || ! hash_equals((string) ($meta['ohlcv_repair']['snapshot_sha256'] ?? ''), hash('sha256', implode('', $afterHashes)))) {
            throw new \App\Exceptions\DomainException('Stored exact row snapshot failed its metadata integrity check.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
        }
        $ids = $snapshots->pluck('stock_price_id')->map(fn ($id) => (int) $id)->all();
        // The whole affected interval is part of the exact image. Newly inserted
        // rows must fail closed just like deleted or modified captured rows.
        $current = DB::table('portfolio_stock_prices')->where('stock_id', $factor->stock_id)
            ->where('price_date', '<', $factor->effective_ex_date->toDateString())
            ->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
        if (count($current) !== count($ids) || array_column($current, 'id') !== $ids) {
            throw new \App\Exceptions\DomainException('Price row set drifted since repair.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
        }
        $invalidated = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)->invalidateRepairProof($factor->stock);
        foreach ($current as $index => $row) {
            if (! hash_equals((string) $snapshots[$index]->after_sha256, $this->rowHash($row))) {
                throw new \App\Exceptions\DomainException('Price rows drifted since repair; exact rollback refused.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
            }
        }
        foreach ($snapshots as $snapshot) {
            $before = json_decode($snapshot->before_row, true, flags: JSON_THROW_ON_ERROR);
            $id = (int) $before['id']; unset($before['id']);
            DB::table('portfolio_stock_prices')->where('id', $id)->update($before);
        }
        $restoredRows = DB::table('portfolio_stock_prices')->where('stock_id', $factor->stock_id)
            ->where('price_date', '<', $factor->effective_ex_date->toDateString())->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        if (count($restoredRows) !== count($snapshots)
            || ! hash_equals((string) $meta['ohlcv_repair']['snapshot_before_sha256'], hash('sha256', implode('', array_map(fn ($r) => $this->rowHash($r), $restoredRows))))
        ) {
            throw new \App\Exceptions\DomainException('Restored price row set failed exact before-image verification.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
        }
        app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)->refreshOverlappingRepairProofs($factor->stock, $invalidated);
        return ['rows_restored' => count($ids), 'rows_sha256' => hash('sha256', implode('', array_map(fn ($s) => hash('sha256', $s->before_row), $snapshots->all())))];
    }

    private function rowHash(array $row): string
    {
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function factorPreflightEvidence(PriceAdjustmentFactor $factor, string $exDate): array
    {
        $rows = DB::table('portfolio_stock_prices')
            ->where('stock_id', $factor->stock_id)
            ->where('price_date', '<', $exDate)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
        $issue = $factor->issue;

        $protectedRowsSha256 = hash('sha256', implode('', array_map(fn (array $row) => $this->rowHash($row), $rows)));
        return [
            'preflight_sha256' => hash('sha256', json_encode([
            'factor' => $factor->getAttributes(),
            'approval' => $issue === null ? null : [
                'issue' => $issue->getAttributes(),
                'resolution' => $issue->linkedResolution?->getAttributes(),
            ],
            'rows' => [
                'count' => count($rows),
                'sha256' => $protectedRowsSha256,
            ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'protected_rows_count' => count($rows),
            'protected_rows_sha256' => $protectedRowsSha256,
        ];
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
