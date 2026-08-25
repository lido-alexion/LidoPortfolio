<?php

namespace App\Services;

use App\Models\CorporateAction;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CorporateActionService
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected TransactionRealizationService $realizations,
        protected PortfolioSnapshotRebuildService $snapshotRebuild,
        protected StockResolverService $stocks,
        protected CorporateActionPriceAdjustmentService $priceAdjustment,
        protected MetricsUpdateService $metricsUpdate,
        protected TransactionWriteService $writes,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(PortfolioProfile $profile, Stock $stock, array $input): array
    {
        $payload = $this->normalizeInput($input);

        return $payload['action_type'] === 'split'
            ? $this->previewSplit($profile, $stock, $payload)
            : $this->previewBonus($profile, $stock, $payload);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function apply(PortfolioProfile $profile, Stock $stock, array $input): array
    {
        $preview = $this->preview($profile, $stock, $input);

        if (! empty($preview['blocking_errors'])) {
            throw new InvalidArgumentException(implode(' ', $preview['blocking_errors']));
        }

        $payload = $this->normalizeInput($input);
        $userId = auth()->id();

        $action = DB::transaction(function () use ($profile, $stock, $payload, $preview, $userId) {
            $action = CorporateAction::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $stock->id,
                'action_type' => $payload['action_type'],
                'ratio_from' => $payload['ratio_from'],
                'ratio_to' => $payload['ratio_to'],
                'ex_date' => $payload['ex_date'],
                'notes' => $payload['notes'],
                'applied_at' => now(),
                'created_by' => $userId,
                'metadata' => $preview['metadata'] ?? null,
            ]);

            if ($payload['action_type'] === 'split') {
                $this->applySplit($action, $preview);
            } else {
                $this->applyBonus($profile, $stock, $action, $preview);
            }

            return $action;
        });

        // Single OHLCV writer invariant: when an active F042 factor owns this
        // stock+ex-date+action event, F043 performs historical price repair.
        // F020 still applies ledger changes above; only OHLCV mutation is skipped.
        $delegatingFactor = PriceAdjustmentFactor::findActiveOhlcvRepairForEvent(
            (int) $stock->id,
            $payload['ex_date'],
            $payload['action_type'],
        );

        if ($delegatingFactor !== null) {
            $factors = $this->priceAdjustment->factorsForAction(
                $payload['action_type'],
                $payload['ratio_from'],
                $payload['ratio_to'],
            );
            $priceAdjustment = [
                'rows_adjusted' => 0,
                'price_divisor' => $factors['price_divisor'],
                'volume_multiplier' => $factors['volume_multiplier'],
                'adjusted_before_date' => $payload['ex_date'],
                'deferred_to_factor' => true,
                'factor_id' => $delegatingFactor->id,
                'ohlcv_repair_status' => $delegatingFactor->metadata['ohlcv_repair_status'] ?? null,
                'repair_note' => 'ohlcv_delegated_to_f043_price_adjustment_factor',
            ];
        } else {
            $priceAdjustment = $this->priceAdjustment->adjustHistoricalPrices(
                $stock,
                $payload['ex_date'],
                $payload['action_type'],
                $payload['ratio_from'],
                $payload['ratio_to'],
            );
        }

        $action->update([
            'metadata' => array_merge($action->metadata ?? [], [
                'price_adjustment' => $priceAdjustment,
            ]),
        ]);

        $holdings = $this->holdings->recalculateOwnerLotsForProfileStock($profile, $stock);
        $holding = $holdings->first() ?? $this->holdings->recalculateForProfileStock($profile, $stock);
        $this->realizations->recalculateForProfileStock($profile, $stock);
        $this->metricsUpdate->updateStock($stock);
        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $profile,
            $payload['ex_date'],
            $payload['ex_date'],
        );

        return [
            'corporate_action' => $action->fresh()->load('stock'),
            // BC: single representative row; `holdings` exposes all OD-01 owner lots.
            'holding' => $holding->load('stock'),
            'holdings' => $holdings->map(fn (Holding $h) => $h->load('stock'))->values()->all(),
            'price_adjustment' => $priceAdjustment,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   action_type: string,
     *   ratio_from: int,
     *   ratio_to: int,
     *   ex_date: string,
     *   notes: ?string,
     *   split_scope: string
     * }
     */
    protected function normalizeInput(array $input): array
    {
        $actionType = (string) ($input['action_type'] ?? '');
        if (! in_array($actionType, ['split', 'bonus'], true)) {
            throw new InvalidArgumentException('action_type must be split or bonus.');
        }

        $ratioFrom = (int) ($input['ratio_from'] ?? 0);
        $ratioTo = (int) ($input['ratio_to'] ?? 0);
        if ($ratioFrom < 1 || $ratioTo < 1) {
            throw new InvalidArgumentException('ratio_from and ratio_to must be at least 1.');
        }

        $exDate = (string) ($input['ex_date'] ?? '');
        if ($exDate === '') {
            throw new InvalidArgumentException('ex_date is required.');
        }

        $splitScope = (string) ($input['split_scope'] ?? 'all');
        if (! in_array($splitScope, ['all', 'before_ex_date'], true)) {
            $splitScope = 'all';
        }

        return [
            'action_type' => $actionType,
            'ratio_from' => $ratioFrom,
            'ratio_to' => $ratioTo,
            'ex_date' => $exDate,
            'notes' => isset($input['notes']) ? (string) $input['notes'] : null,
            'split_scope' => $splitScope,
        ];
    }

    protected function ratioFactor(int $ratioFrom, int $ratioTo): float
    {
        return $ratioTo / $ratioFrom;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function previewSplit(PortfolioProfile $profile, Stock $stock, array $payload): array
    {
        $factor = $this->ratioFactor($payload['ratio_from'], $payload['ratio_to']);
        $transactions = $this->scopedTransactions($profile, $stock, $payload);
        $warnings = $this->baseWarnings($profile, $stock, $payload);
        $blockingErrors = [];

        if ($transactions->isEmpty()) {
            $blockingErrors[] = 'No transactions found for this stock in the active portfolio.';
        }

        if ($payload['split_scope'] === 'before_ex_date') {
            $postExCount = $this->holdings->transactionsForProfileStock($profile, $stock)
                ->filter(fn (Transaction $tx) => $tx->transaction_date->format('Y-m-d') > $payload['ex_date'])
                ->count();
            if ($postExCount > 0) {
                $warnings[] = "{$postExCount} transaction(s) after the ex-date will not be adjusted. Verify their quantities are already in post-split units.";
            }
        }

        $adjustments = $transactions->map(function (Transaction $transaction) use ($factor) {
            $oldQty = (float) $transaction->quantity;
            $oldPrice = (float) $transaction->price;

            return [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
                'old_quantity' => round($oldQty, 4),
                'old_price' => round($oldPrice, 4),
                'new_quantity' => round($oldQty * $factor, 4),
                'new_price' => round($oldPrice / $factor, 4),
            ];
        })->values()->all();

        $simulated = $this->simulatePostState($profile, $stock, $adjustments, $payload);
        $pricePreview = $this->priceAdjustment->previewAdjustment(
            $stock,
            $payload['ex_date'],
            $payload['action_type'],
            $payload['ratio_from'],
            $payload['ratio_to'],
        );

        return [
            'action_type' => 'split',
            'ratio_from' => $payload['ratio_from'],
            'ratio_to' => $payload['ratio_to'],
            'ex_date' => $payload['ex_date'],
            'split_scope' => $payload['split_scope'],
            'factor' => $factor,
            'adjustments' => $adjustments,
            'warnings' => $warnings,
            'blocking_errors' => $blockingErrors,
            'post_state' => $simulated,
            'price_adjustment' => $pricePreview,
            'metadata' => [
                'factor' => $factor,
                'split_scope' => $payload['split_scope'],
                'adjustments' => $adjustments,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function previewBonus(PortfolioProfile $profile, Stock $stock, array $payload): array
    {
        $bonusFactor = $this->ratioFactor($payload['ratio_from'], $payload['ratio_to']);
        $byOwner = $this->holdings->quantityAsOfDateByOwner($profile, $stock, $payload['ex_date']);
        $warnings = $this->baseWarnings($profile, $stock, $payload);
        $blockingErrors = [];

        $allocations = [];
        $eligibleQty = 0.0;
        $bonusQty = 0.0;

        foreach ($byOwner['quantities'] as $ownerKey => $ownerEligible) {
            $ownerBonus = round((float) $ownerEligible * $bonusFactor, 4);
            if ($ownerBonus <= 0.00001) {
                continue;
            }

            $eligibleQty += (float) $ownerEligible;
            $bonusQty += $ownerBonus;

            $recommendationId = null;
            if ($byOwner['attributable'] && $ownerKey !== Holding::OWNER_UNMANAGED) {
                $recommendationId = $this->parentRecommendationIdForOwner(
                    $byOwner['lots'][$ownerKey] ?? collect()
                );
            }

            $allocations[] = [
                'owner_key' => $ownerKey,
                'eligible_quantity' => round((float) $ownerEligible, 4),
                'bonus_quantity' => $ownerBonus,
                'recommendation_id' => $recommendationId,
            ];
        }

        $eligibleQty = round($eligibleQty, 4);
        $bonusQty = round($bonusQty, 4);

        if ($eligibleQty <= 0.00001) {
            $blockingErrors[] = 'No shares were held on the record date, so no bonus can be applied.';
        }

        if ($bonusQty <= 0.00001) {
            $blockingErrors[] = 'Bonus quantity rounds to zero.';
        }

        $proposedBuys = array_map(static fn (array $row) => [
            'transaction_date' => $payload['ex_date'],
            'type' => 'buy',
            'quantity' => $row['bonus_quantity'],
            'price' => 0,
            'fees' => 0,
            'owner_key' => $row['owner_key'],
            'recommendation_id' => $row['recommendation_id'],
        ], $allocations);

        // BC aggregate row (sum of owner-scoped bonus buys).
        $proposedBuy = [
            'transaction_date' => $payload['ex_date'],
            'type' => 'buy',
            'quantity' => $bonusQty,
            'price' => 0,
            'fees' => 0,
        ];

        $postState = $this->holdings->replayTransactions(
            $this->holdings->transactionsForProfileStock($profile, $stock),
            dryRun: true,
        );

        $postQty = round($postState['quantity'] + $bonusQty, 4);
        $postInvested = round($postState['invested_amount'], 4);
        $postAvg = $postQty > 0 ? round($postInvested / $postQty, 4) : 0;
        $pricePreview = $this->priceAdjustment->previewAdjustment(
            $stock,
            $payload['ex_date'],
            $payload['action_type'],
            $payload['ratio_from'],
            $payload['ratio_to'],
        );

        return [
            'action_type' => 'bonus',
            'ratio_from' => $payload['ratio_from'],
            'ratio_to' => $payload['ratio_to'],
            'ex_date' => $payload['ex_date'],
            'eligible_quantity' => $eligibleQty,
            'bonus_quantity' => $bonusQty,
            'ownership_attributable' => $byOwner['attributable'],
            'owner_allocations' => $allocations,
            'proposed_buy' => $proposedBuy,
            'proposed_buys' => $proposedBuys,
            'warnings' => $warnings,
            'blocking_errors' => $blockingErrors,
            'post_state' => [
                'quantity' => $postQty,
                'avg_buy_price' => $postAvg,
                'invested_amount' => $postInvested,
            ],
            'price_adjustment' => $pricePreview,
            'metadata' => [
                'eligible_quantity' => $eligibleQty,
                'bonus_quantity' => $bonusQty,
                'ownership_attributable' => $byOwner['attributable'],
                'owner_allocations' => $allocations,
                'proposed_buy' => $proposedBuy,
                'proposed_buys' => $proposedBuys,
            ],
        ];
    }

    /**
     * Reuse a parent buy's recommendation so CA quantity inherits that strategy owner (OD-10).
     * Does not create a new BUY opportunity (avoids OD-11 cooldown side effects).
     *
     * @param  Collection<int, Transaction>  $ownerTxs
     */
    protected function parentRecommendationIdForOwner(Collection $ownerTxs): ?int
    {
        foreach ($ownerTxs->reverse() as $transaction) {
            if ($transaction->type === 'buy' && $transaction->recommendation_id !== null) {
                return (int) $transaction->recommendation_id;
            }
        }

        return null;
    }

    /**
     * Rare fallback: strategy-owned open qty with no recommendation-linked buy in the lot.
     * Uses HOLD_POSITION so OD-11 BUY cooldown does not treat this as a new BUY opportunity.
     */
    protected function ensureAttributionRecommendation(
        PortfolioProfile $profile,
        Stock $stock,
        string $ownerKey,
        CorporateAction $action,
    ): ?int {
        if ($ownerKey === Holding::OWNER_UNMANAGED || ! str_starts_with($ownerKey, 'strategy:')) {
            return null;
        }

        $strategyId = (int) substr($ownerKey, strlen('strategy:'));
        $strategy = TradingStrategy::query()->with('activeVersion')->find($strategyId);
        $versionId = $strategy?->active_version_id;
        if ($versionId === null) {
            return null;
        }

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $versionId,
            'recommendation_type' => TradingRecommendation::ACTION_HOLD_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 0,
            'strategy_score' => 0,
            'confidence' => 0,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => $action->ex_date,
            'evidence' => [
                'corporate_action_attribution' => true,
                'corporate_action_id' => $action->id,
            ],
        ]);

        return (int) $rec->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, Transaction>
     */
    protected function scopedTransactions(PortfolioProfile $profile, Stock $stock, array $payload): Collection
    {
        $transactions = $this->holdings->transactionsForProfileStock($profile, $stock);

        if ($payload['action_type'] === 'split' && $payload['split_scope'] === 'before_ex_date') {
            return $transactions
                ->filter(fn (Transaction $tx) => $tx->transaction_date->format('Y-m-d') <= $payload['ex_date'])
                ->values();
        }

        return $transactions;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function baseWarnings(PortfolioProfile $profile, Stock $stock, array $payload): array
    {
        $warnings = [];

        $duplicate = CorporateAction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('action_type', $payload['action_type'])
            ->whereDate('ex_date', $payload['ex_date'])
            ->whereNotNull('applied_at')
            ->exists();

        if ($duplicate) {
            $warnings[] = 'A '.$payload['action_type'].' for this stock on the same ex-date was already applied.';
        }

        $pricePreview = $this->priceAdjustment->previewAdjustment(
            $stock,
            $payload['ex_date'],
            $payload['action_type'],
            $payload['ratio_from'],
            $payload['ratio_to'],
        );

        if (($pricePreview['rows_to_adjust'] ?? 0) > 0) {
            $warnings[] = sprintf(
                '%d cached OHLCV row(s) before %s will be restated (÷%.4g price, ×%.4g volume) so charts stay continuous.',
                $pricePreview['rows_to_adjust'],
                $payload['ex_date'],
                $pricePreview['price_divisor'],
                $pricePreview['volume_multiplier'],
            );
        } else {
            $warnings[] = 'No cached OHLCV rows exist before the ex-date; only the transaction ledger will change.';
        }

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $adjustments
     * @param  array<string, mixed>  $payload
     * @return array<string, float>
     */
    protected function simulatePostState(
        PortfolioProfile $profile,
        Stock $stock,
        array $adjustments,
        array $payload,
    ): array {
        $adjustmentMap = collect($adjustments)->keyBy('transaction_id');
        $transactions = $this->holdings->transactionsForProfileStock($profile, $stock)
            ->map(function (Transaction $transaction) use ($adjustmentMap, $payload) {
                if ($payload['action_type'] === 'split' && $payload['split_scope'] === 'before_ex_date') {
                    if ($transaction->transaction_date->format('Y-m-d') > $payload['ex_date']) {
                        return $transaction;
                    }
                }

                $row = $adjustmentMap->get($transaction->id);
                if ($row === null) {
                    return $transaction;
                }

                $clone = clone $transaction;
                $clone->quantity = $row['new_quantity'];
                $clone->price = $row['new_price'];

                return $clone;
            });

        $state = $this->holdings->replayTransactions($transactions, dryRun: true);

        return [
            'quantity' => round($state['quantity'], 4),
            'avg_buy_price' => round($state['avg_buy_price'], 4),
            'invested_amount' => round($state['invested_amount'], 4),
            'realized_profit' => round($state['realized_profit'], 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    protected function applySplit(CorporateAction $action, array $preview): void
    {
        foreach ($preview['adjustments'] as $row) {
            $transaction = Transaction::query()
                ->where('id', $row['transaction_id'])
                ->where('profile_id', $action->profile_id)
                ->firstOrFail();

            $transaction->update([
                'quantity' => $row['new_quantity'],
                'price' => $row['new_price'],
                'corporate_action_id' => $action->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $preview
     *
     * Uses TransactionWriteService::insert (not create) because the caller (apply()) already
     * recalculates holdings/realizations/snapshots afterwards — applyAfterCreate would duplicate that work.
     *
     * OD-10: one SOURCE_BONUS buy per parent owner when ownership is attributable; each
     * strategy-owned bonus reuses (or rarely creates) a recommendation so qty stays on that owner.
     * Ambiguous/blended ledgers keep a single unmanaged bonus (no invented owners).
     */
    protected function applyBonus(PortfolioProfile $profile, Stock $stock, CorporateAction $action, array $preview): void
    {
        $ratioLabel = $action->ratio_from.':'.$action->ratio_to;
        $buys = $preview['proposed_buys'] ?? null;

        if (! is_array($buys) || $buys === []) {
            $buys = [[
                'transaction_date' => $preview['proposed_buy']['transaction_date'] ?? $action->ex_date?->format('Y-m-d'),
                'quantity' => $preview['proposed_buy']['quantity'] ?? $preview['bonus_quantity'] ?? 0,
                'owner_key' => Holding::OWNER_UNMANAGED,
                'recommendation_id' => null,
            ]];
        }

        foreach ($buys as $buy) {
            $qty = (float) ($buy['quantity'] ?? 0);
            if ($qty <= 0.00001) {
                continue;
            }

            $ownerKey = (string) ($buy['owner_key'] ?? Holding::OWNER_UNMANAGED);
            $recommendationId = isset($buy['recommendation_id']) ? (int) $buy['recommendation_id'] : null;

            if ($recommendationId === null && $ownerKey !== Holding::OWNER_UNMANAGED) {
                $recommendationId = $this->ensureAttributionRecommendation(
                    $profile,
                    $stock,
                    $ownerKey,
                    $action,
                );
            }

            $ownerNote = $ownerKey === Holding::OWNER_UNMANAGED
                ? ''
                : ' [owner '.$ownerKey.']';

            $this->writes->insert($profile, $stock, [
                'type' => 'buy',
                'quantity' => $qty,
                'price' => 0,
                'fees' => 0,
                'transaction_date' => $buy['transaction_date'] ?? $action->ex_date?->format('Y-m-d'),
                'notes' => trim(
                    'Bonus '.$ratioLabel.' (corporate action #'.$action->id.')'
                    .$ownerNote
                    .($action->notes ? ' — '.$action->notes : '')
                ),
                'source' => Transaction::SOURCE_BONUS,
                'corporate_action_id' => $action->id,
                'recommendation_id' => $recommendationId,
            ]);
        }
    }
}
