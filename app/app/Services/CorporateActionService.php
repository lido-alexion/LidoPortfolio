<?php

namespace App\Services;

use App\Models\CorporateAction;
use App\Models\PortfolioProfile;
use App\Models\Stock;
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
                $this->applyBonus($action, $preview);
            }

            return $action;
        });

        $priceAdjustment = $this->priceAdjustment->adjustHistoricalPrices(
            $stock,
            $payload['ex_date'],
            $payload['action_type'],
            $payload['ratio_from'],
            $payload['ratio_to'],
        );

        $action->update([
            'metadata' => array_merge($action->metadata ?? [], [
                'price_adjustment' => $priceAdjustment,
            ]),
        ]);

        $holding = $this->holdings->recalculateForProfileStock($profile, $stock);
        $this->realizations->recalculateForProfileStock($profile, $stock);
        $this->metricsUpdate->updateStock($stock);
        $this->snapshotRebuild->rebuildAfterTransactionChange(
            $profile,
            $payload['ex_date'],
            $payload['ex_date'],
        );

        return [
            'corporate_action' => $action->fresh()->load('stock'),
            'holding' => $holding->load('stock'),
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
        $eligibleQty = $this->holdings->quantityAsOfDate($profile, $stock, $payload['ex_date']);
        $bonusQty = round($eligibleQty * $bonusFactor, 4);
        $warnings = $this->baseWarnings($profile, $stock, $payload);
        $blockingErrors = [];

        if ($eligibleQty <= 0.00001) {
            $blockingErrors[] = 'No shares were held on the record date, so no bonus can be applied.';
        }

        if ($bonusQty <= 0.00001) {
            $blockingErrors[] = 'Bonus quantity rounds to zero.';
        }

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
            'eligible_quantity' => round($eligibleQty, 4),
            'bonus_quantity' => $bonusQty,
            'proposed_buy' => $proposedBuy,
            'warnings' => $warnings,
            'blocking_errors' => $blockingErrors,
            'post_state' => [
                'quantity' => $postQty,
                'avg_buy_price' => $postAvg,
                'invested_amount' => $postInvested,
            ],
            'price_adjustment' => $pricePreview,
            'metadata' => [
                'eligible_quantity' => round($eligibleQty, 4),
                'bonus_quantity' => $bonusQty,
                'proposed_buy' => $proposedBuy,
            ],
        ];
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
     */
    protected function applyBonus(CorporateAction $action, array $preview): void
    {
        $buy = $preview['proposed_buy'];
        $ratioLabel = $action->ratio_from.':'.$action->ratio_to;

        Transaction::query()->create([
            'profile_id' => $action->profile_id,
            'stock_id' => $action->stock_id,
            'type' => 'buy',
            'quantity' => $buy['quantity'],
            'price' => 0,
            'fees' => 0,
            'transaction_date' => $buy['transaction_date'],
            'notes' => trim('Bonus '.$ratioLabel.' (corporate action #'.$action->id.')'.($action->notes ? ' — '.$action->notes : '')),
            'corporate_action_id' => $action->id,
        ]);
    }
}
