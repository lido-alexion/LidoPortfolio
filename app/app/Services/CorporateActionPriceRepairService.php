<?php

namespace App\Services;

use App\Models\CorporateAction;
use App\Models\Stock;
use App\Models\StockPrice;

class CorporateActionPriceRepairService
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING_METADATA = 'missing_metadata';

    public const STATUS_SUSPECTED_UNADJUSTED = 'suspected_unadjusted';

    public const STATUS_SUSPECTED_ALREADY_ADJUSTED = 'suspected_already_adjusted';

    public const STATUS_NO_PRICES = 'no_prices';

    public const STATUS_AMBIGUOUS = 'ambiguous';

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
