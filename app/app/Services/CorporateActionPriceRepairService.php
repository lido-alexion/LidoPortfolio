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

                    $this->markMetadataOnly(CorporateAction::query()->findOrFail($actionIdValue), $finding);
                    $details[] = [
                        'corporate_action_id' => $actionIdValue,
                        'action' => 'marked_metadata_only',
                        'status' => $status,
                    ];
                    $repaired++;
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

            $action = CorporateAction::query()->with('stock')->findOrFail($actionIdValue);
            $stock = $action->stock;
            if ($stock === null) {
                $skipped++;
                continue;
            }

            $adjustment = $this->priceAdjustment->adjustHistoricalPrices(
                $stock,
                $action->ex_date->toDateString(),
                $action->action_type,
                (int) $action->ratio_from,
                (int) $action->ratio_to,
            );

            $action->update([
                'metadata' => array_merge($action->metadata ?? [], [
                    'price_adjustment' => array_merge($adjustment, [
                        'repaired_at' => now()->toIso8601String(),
                        'repair_source' => 'portfolio:repair-corporate-action-prices',
                    ]),
                ]),
            ]);

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
        return DB::transaction(function () use ($factorId, $repairSource) {
            /** @var PriceAdjustmentFactor|null $factor */
            $factor = PriceAdjustmentFactor::query()
                ->with(['stock', 'issue'])
                ->whereKey($factorId)
                ->lockForUpdate()
                ->first();

            if ($factor === null) {
                return [
                    'factor_id' => $factorId,
                    'action' => 'skipped',
                    'status' => self::STATUS_INVALID_FACTOR,
                    'reason' => 'Factor not found.',
                ];
            }

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
            $metadata['ohlcv_repair_status'] = PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED;
            $metadata['ohlcv_repair'] = [
                'previous_status' => $previousStatus,
                'resulting_status' => PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED,
                'rows_adjusted' => $adjustment['rows_adjusted'],
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
    protected function markMetadataOnly(CorporateAction $action, array $finding): void
    {
        $stock = $action->stock;
        if ($stock === null) {
            return;
        }

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
    }
}
