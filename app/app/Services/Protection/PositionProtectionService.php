<?php

namespace App\Services\Protection;

use App\Engines\Execution\ExecutionEngine;
use App\Engines\Execution\ExecutionGate;
use App\Exceptions\DomainException;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PositionProtection;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\Broker\BrokerAmbiguousException;
use App\Services\Broker\BrokerGateway;
use App\Services\Broker\BrokerGttRequest;
use App\Services\PortfolioLoggerService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * V4-FEAT-002 — one active GTT Target or Stop-Loss per Strategy position.
 */
class PositionProtectionService
{
    public const MAX_RETRIES = 3;

    public const DEFAULT_AUTOMATIC_TYPE = PositionProtection::TYPE_STOP;

    public bool $applyingGttFill = false;

    public function __construct(
        protected BrokerGateway $broker,
        protected ExecutionGate $gate,
        protected ExecutionEngine $execution,
        protected ProtectionTriggerPriceResolver $prices,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return Collection<int, PositionProtection>
     */
    public function listForProfile(PortfolioProfile $profile, ?int $holdingId = null, ?int $stockId = null): Collection
    {
        $query = PositionProtection::query()->forProfile($profile)->orderByDesc('id');
        if ($holdingId) {
            $query->where('holding_id', $holdingId);
        }
        if ($stockId) {
            $query->where('stock_id', $stockId);
        }

        return $query->get();
    }

    public function findForProfile(PortfolioProfile $profile, int $id): PositionProtection
    {
        $row = PositionProtection::query()->forProfile($profile)->where('id', $id)->first();
        if (! $row) {
            throw new DomainException('Protection not found for this portfolio.', 'NOT_FOUND', 404);
        }

        return $row;
    }

    public function place(
        User $user,
        PortfolioProfile $profile,
        int $holdingId,
        string $type,
        #[\SensitiveParameter] ?string $totpCode = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ): PositionProtection {
        $this->gate->assertCanSubmitBroker($user, $profile, ExecutionGate::TRIGGER_SEMI, $totpCode, $recoveryCode);
        $holding = $this->strategyHolding($profile, $holdingId);

        return $this->placeOrReplace($profile, $user, $holding, $type, 'user_place', attended: true);
    }

    public function cancel(
        User $user,
        PortfolioProfile $profile,
        int $id,
        #[\SensitiveParameter] ?string $totpCode = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ): PositionProtection {
        $this->gate->assertCanSubmitBroker($user, $profile, ExecutionGate::TRIGGER_SEMI, $totpCode, $recoveryCode);
        $protection = $this->findForProfile($profile, $id);
        $this->cancelAtBroker($profile, $protection, PositionProtection::STATE_CANCELLED, 'user_cancel');

        return $protection->fresh();
    }

    public function reconcileOne(PortfolioProfile $profile, PositionProtection $protection): PositionProtection
    {
        if ((int) $protection->profile_id !== (int) $profile->id) {
            throw new DomainException('Protection not found for this portfolio.', 'NOT_FOUND', 404);
        }

        $id = (int) $protection->id;
        $wasDeferred = (bool) $protection->sync_deferred;
        $this->ingestGttFills($profile, $protection);
        $protection = PositionProtection::query()->find($id);
        if (! $protection || ! $protection->isOpen()) {
            return $protection ?? $this->findForProfile($profile, $id);
        }

        $holding = $this->holdingFor($protection);
        if ($holding === null || (float) $holding->quantity <= 0.0001) {
            $this->clearForHolding($profile, $protection->holding_id, $protection->stock_id, $protection->owner_key);

            return $protection->fresh();
        }

        if ($protection->sync_deferred && ! $wasDeferred) {
            return $protection;
        }

        if ($protection->sync_deferred) {
            $protection->forceFill(['sync_deferred' => false])->save();

            return $this->synchronize($profile, $holding, $protection, 'deferred_reconcile');
        }

        if (in_array($protection->state, [PositionProtection::STATE_SYNCHRONIZING, PositionProtection::STATE_NEEDS_ATTENTION, PositionProtection::STATE_PENDING], true)
            || $protection->broker_status === PositionProtection::BROKER_UNKNOWN) {
            return $this->synchronize($profile, $holding, $protection, $protection->last_sync_reason ?: 'reconcile_retry');
        }

        if ($protection->broker_gtt_id) {
            $snap = $this->broker->fetchGtt((int) $profile->user_id, (string) $protection->broker_gtt_id);
            if ($snap) {
                $protection->forceFill([
                    'broker_status' => $this->mapGttStatus($snap->status),
                    'last_broker_sync_at' => now(),
                ])->save();
            }
        }

        return $protection->fresh();
    }

    public function reconcileOpenForProfile(PortfolioProfile $profile): int
    {
        $rows = PositionProtection::query()->forProfile($profile)->open()->orderBy('id')->get();
        $n = 0;
        foreach ($rows as $row) {
            $this->reconcileOne($profile, $row);
            $n++;
        }

        return $n;
    }

    /**
     * Material BUY/SELL after the ledger unit has committed. Never auto-places.
     */
    public function afterCommittedFill(PortfolioProfile $profile, Stock $stock, string $type, ?string $source = null): void
    {
        if ($this->applyingGttFill) {
            return;
        }
        $type = strtolower($type);
        if (! in_array($type, ['buy', 'sell'], true)) {
            return;
        }
        // V4-SPEC-002: rights-tagged buys are purchases (subscription price),
        // not corporate-action restatement. Only bonus/split skip GTT sync.
        if (in_array($source, ['bonus', 'split'], true)) {
            return;
        }

        $holdings = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->whereNotNull('strategy_id')
            ->get();

        foreach ($holdings as $holding) {
            if ((float) $holding->quantity <= 0.0001) {
                $this->clearForHolding($profile, $holding->id, $holding->stock_id, $holding->owner_key);
                continue;
            }
            $existing = $this->openForHolding($profile, $holding);
            if ($existing) {
                $this->synchronize($profile, $holding, $existing, $type === 'buy' ? 'material_buy' : 'material_sell');
            }
        }
    }

    public function afterAutomaticBuyFill(PortfolioProfile $profile, Holding $holding): void
    {
        $profile = $profile->fresh() ?? $profile;
        if ($profile->executionMode() !== PortfolioProfile::EXECUTION_MODE_AUTOMATIC) {
            return;
        }
        if ($holding->isUnmanaged() || (float) $holding->quantity <= 0.0001) {
            return;
        }
        if ($this->openForHolding($profile, $holding)) {
            return;
        }
        $user = $profile->user ?? User::query()->find($profile->user_id);
        if (! $user) {
            return;
        }
        try {
            $this->gate->assertCanSubmitBroker($user, $profile, ExecutionGate::TRIGGER_AUTOMATIC);
        } catch (DomainException $e) {
            $this->ensureRow($profile, $holding, self::DEFAULT_AUTOMATIC_TYPE);
            $row = $this->openForHolding($profile, $holding);
            if ($row) {
                $this->markNeedsAttention($row, $e->errorCode());
            }

            return;
        }

        $this->placeOrReplace($profile, $user, $holding, self::DEFAULT_AUTOMATIC_TYPE, 'automatic_buy', attended: false);
    }

    public function afterCorporateAction(PortfolioProfile $profile, Stock $stock): void
    {
        $holdings = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->whereNotNull('strategy_id')
            ->where('quantity', '>', 0)
            ->get();
        foreach ($holdings as $holding) {
            $existing = $this->openForHolding($profile, $holding);
            if ($existing) {
                $this->synchronize($profile, $holding, $existing, 'corporate_action');
            }
        }
    }

    public function afterAdoption(PortfolioProfile $profile, Holding $holding): void
    {
        if ($holding->isUnmanaged()) {
            return;
        }
        $existing = $this->openForHolding($profile, $holding);
        if ($existing) {
            $this->synchronize($profile, $holding, $existing, 'adoption_merge');
        }
    }

    public function present(PositionProtection $protection): array
    {
        return [
            'id' => $protection->id,
            'holding_id' => $protection->holding_id,
            'stock_id' => $protection->stock_id,
            'strategy_id' => $protection->strategy_id,
            'owner_key' => $protection->owner_key,
            'protection_type' => $protection->protection_type,
            'state' => $protection->state,
            'trigger_price' => $protection->trigger_price !== null ? (float) $protection->trigger_price : null,
            'quantity' => (float) $protection->quantity,
            'broker_gtt_id' => $protection->broker_gtt_id,
            'broker_status' => $protection->broker_status,
            'retry_count' => (int) $protection->retry_count,
            'last_error' => $protection->last_error,
            'last_sync_reason' => $protection->last_sync_reason,
            'sync_deferred' => (bool) $protection->sync_deferred,
            'last_broker_sync_at' => optional($protection->last_broker_sync_at)?->toIso8601String(),
            'needs_attention_at' => optional($protection->needs_attention_at)?->toIso8601String(),
            'updated_at' => optional($protection->updated_at)?->toIso8601String(),
        ];
    }

    protected function placeOrReplace(
        PortfolioProfile $profile,
        User $user,
        Holding $holding,
        string $type,
        string $reason,
        bool $attended,
    ): PositionProtection {
        if (! in_array($type, PositionProtection::TYPES, true)) {
            throw new DomainException('Protection type must be target or stop.', 'VALIDATION_ERROR', 422);
        }

        $existing = $this->openForHolding($profile, $holding);
        if ($existing && $existing->protection_type !== $type) {
            if ($existing->broker_status === PositionProtection::BROKER_UNKNOWN) {
                throw new DomainException(
                    'Existing protection is still reconciling; will not place a duplicate.',
                    'PROTECTION_AMBIGUOUS',
                    409,
                );
            }
            if ($existing->broker_gtt_id) {
                $this->cancelAtBroker($profile, $existing, PositionProtection::STATE_CANCELLED, 'replaced_by_'.$type);
                $existing = $existing->fresh();
                if ($existing && $existing->isOpen()) {
                    throw new DomainException(
                        'Existing protection is still reconciling; will not place a duplicate.',
                        'PROTECTION_AMBIGUOUS',
                        409,
                    );
                }
            } else {
                $this->markTerminal($existing, PositionProtection::STATE_CANCELLED, 'replaced_by_'.$type);
            }
        }

        $row = $this->ensureRow($profile, $holding, $type);
        $price = $this->prices->triggerPrice($profile, $holding, $type);
        if ($price === null) {
            $this->markNeedsAttention($row, $type === PositionProtection::TYPE_TARGET ? 'missing_target' : 'missing_stop');

            return $row->fresh();
        }

        return $this->synchronize($profile, $holding, $row, $reason, $price);
    }

    protected function synchronize(
        PortfolioProfile $profile,
        Holding $holding,
        PositionProtection $protection,
        string $reason,
        ?float $price = null,
    ): PositionProtection {
        $qty = (float) $holding->quantity;
        if ($qty <= 0.0001) {
            $this->clearForHolding($profile, $holding->id, $holding->stock_id, $holding->owner_key);

            return $protection->fresh();
        }

        $type = $protection->protection_type;
        $price ??= $this->prices->triggerPrice($profile, $holding, $type);
        if ($price === null) {
            $this->markNeedsAttention($protection, $type === PositionProtection::TYPE_TARGET ? 'missing_target' : 'missing_stop');

            return $protection->fresh();
        }

        $qtyRounded = (float) max(1, (int) round($qty));
        $same = $protection->broker_gtt_id
            && abs((float) $protection->quantity - $qtyRounded) < 0.0001
            && abs((float) $protection->trigger_price - $price) < 0.0001
            && $protection->state === PositionProtection::STATE_ACTIVE
            && $protection->broker_status !== PositionProtection::BROKER_UNKNOWN;

        if ($same) {
            $protection->forceFill(['last_sync_reason' => $reason, 'last_broker_sync_at' => now()])->save();

            return $protection->fresh();
        }

        if ($protection->broker_status === PositionProtection::BROKER_UNKNOWN && ! $protection->broker_gtt_id) {
            return $protection->fresh();
        }

        $protection->forceFill([
            'state' => PositionProtection::STATE_SYNCHRONIZING,
            'trigger_price' => $price,
            'quantity' => $qtyRounded,
            'holding_id' => $holding->id,
            'last_sync_reason' => $reason,
            'last_error' => null,
        ])->save();

        if ($protection->broker_status === PositionProtection::BROKER_UNKNOWN && $protection->broker_gtt_id) {
            $found = $this->broker->fetchGtt((int) $profile->user_id, (string) $protection->broker_gtt_id);
            if ($found && ! in_array($found->status, ['cancelled', 'rejected'], true)) {
                $protection->forceFill([
                    'broker_status' => $this->mapGttStatus($found->status),
                    'last_broker_sync_at' => now(),
                ])->save();
            } else {
                $protection->forceFill([
                    'broker_gtt_id' => null,
                    'broker_status' => null,
                    'last_broker_sync_at' => now(),
                ])->save();
            }
            $protection = $protection->fresh();
        }

        $userId = (int) $profile->user_id;
        $stock = $holding->stock ?? Stock::query()->find($holding->stock_id);
        $request = new BrokerGttRequest(
            userId: $userId,
            profileId: (int) $profile->id,
            symbol: (string) ($stock?->symbol ?? ''),
            exchange: $stock?->exchange ?: 'NSE',
            quantity: $qtyRounded,
            triggerPrice: $price,
            lastPrice: $this->prices->lastPrice($holding, $price),
            submissionKey: $this->submissionKey($protection, $qtyRounded, $price),
            protectionType: $type,
        );

        try {
            if ($protection->broker_gtt_id) {
                try {
                    $placed = $this->broker->modifyGtt($userId, (string) $protection->broker_gtt_id, $request);
                } catch (DomainException $e) {
                    if ($e->errorCode() !== 'MODIFY_UNSUPPORTED') {
                        throw $e;
                    }
                    $this->cancelAtBroker($profile, $protection, PositionProtection::STATE_SYNCHRONIZING, 'modify_unsupported');
                    $protection = $protection->fresh();
                    $placed = $this->broker->placeGtt($request);
                    $protection->forceFill(['broker_gtt_id' => $placed->brokerOrderId])->save();
                }
            } else {
                $placed = $this->broker->placeGtt($request);
                $protection->forceFill(['broker_gtt_id' => $placed->brokerOrderId])->save();
            }
        } catch (BrokerAmbiguousException $e) {
            $protection->forceFill([
                'broker_status' => PositionProtection::BROKER_UNKNOWN,
                'state' => PositionProtection::STATE_SYNCHRONIZING,
                'last_error' => 'ambiguous_broker_response',
                'last_broker_sync_at' => now(),
            ])->save();
            $this->logger->event('PositionProtectionService', 'protection.ambiguous', 'warning', 'GTT outcome unknown; will not duplicate', [
                'profile_id' => $profile->id,
                'protection_id' => $protection->id,
                'user_id' => $userId,
            ]);

            return $protection->fresh();
        } catch (Throwable $e) {
            $retries = (int) $protection->retry_count + 1;
            $protection->forceFill([
                'retry_count' => $retries,
                'last_error' => $e instanceof DomainException ? $e->errorCode() : 'sync_failed',
                'last_broker_sync_at' => now(),
            ])->save();
            if ($retries >= self::MAX_RETRIES) {
                $this->markNeedsAttention($protection, $protection->last_error ?: 'sync_failed');
            }

            return $protection->fresh();
        }

        if (($placed->status ?? '') === 'rejected') {
            $this->markNeedsAttention($protection, 'BROKER_REJECTED');

            return $protection->fresh();
        }

        $protection->forceFill([
            'broker_gtt_id' => $placed->brokerOrderId ?? $protection->broker_gtt_id,
            'broker_status' => PositionProtection::BROKER_ACTIVE,
            'state' => PositionProtection::STATE_ACTIVE,
            'retry_count' => 0,
            'last_error' => null,
            'needs_attention_at' => null,
            'last_broker_sync_at' => now(),
            'submission_key' => $request->submissionKey,
        ])->save();

        $this->logger->event('PositionProtectionService', 'protection.synchronized', 'info', 'Position protection synchronized', [
            'profile_id' => $profile->id,
            'protection_id' => $protection->id,
            'protection_type' => $type,
            'reason' => $reason,
            'broker_gtt_id' => $protection->broker_gtt_id,
        ]);

        return $protection->fresh();
    }

    protected function ingestGttFills(PortfolioProfile $profile, PositionProtection $protection): void
    {
        if (! $protection->broker_gtt_id) {
            return;
        }
        $snap = $this->broker->fetchGtt((int) $profile->user_id, (string) $protection->broker_gtt_id);
        if (! $snap) {
            $protection->forceFill(['last_broker_sync_at' => now()])->save();

            return;
        }

        $protection->forceFill([
            'broker_status' => $this->mapGttStatus($snap->status),
            'last_broker_sync_at' => now(),
        ])->save();

        $filled = (float) $snap->filledQuantity;
        $already = (float) $protection->last_applied_fill_qty;
        $delta = round($filled - $already, 4);
        if ($delta <= 0.0001) {
            if (in_array($snap->status, ['cancelled', 'rejected'], true) && (float) ($this->holdingFor($protection)?->quantity ?? 0) <= 0.0001) {
                $this->markTerminal($protection, PositionProtection::STATE_RECONCILED);
            }

            return;
        }

        $avg = (float) ($snap->averagePrice ?? $protection->trigger_price ?? 0);
        if ($avg <= 0) {
            $this->markNeedsAttention($protection, 'missing_fill_price');

            return;
        }

        $order = $this->ensureFillOrder($profile, $protection, $filled);
        $this->applyingGttFill = true;
        try {
            $this->execution->applyBrokerFill(
                $profile,
                $order,
                $filled,
                $avg,
                completeRecommendation: false,
                ownerKey: $protection->owner_key,
            );
        } finally {
            $this->applyingGttFill = false;
        }

        $protection->forceFill([
            'last_applied_fill_qty' => $filled,
            'trading_order_id' => $order->id,
            'sync_deferred' => true,
        ])->save();

        $holding = $this->holdingFor($protection);
        if ($holding === null || (float) $holding->quantity <= 0.0001) {
            $this->clearForHolding($profile, $protection->holding_id, $protection->stock_id, $protection->owner_key);
        }
    }

    protected function ensureFillOrder(PortfolioProfile $profile, PositionProtection $protection, float $filled): TradingOrder
    {
        if ($protection->trading_order_id) {
            $existing = TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('id', $protection->trading_order_id)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $this->attributionRecommendationId($protection),
            'security_id' => $protection->stock_id,
            'side' => 'sell',
            'quantity' => $protection->quantity > 0 ? $protection->quantity : $filled,
            'order_type' => 'gtt_protection',
            'status' => TradingOrder::STATUS_PENDING,
            'broker_provider' => $this->broker->provider(),
            'broker_status' => TradingOrder::BROKER_PARTIAL,
            'filled_quantity' => 0,
            'notes' => 'GTT protection fill for holding #'.$protection->holding_id,
        ]);
    }

    protected function cancelAtBroker(
        PortfolioProfile $profile,
        PositionProtection $protection,
        string $terminalState,
        string $reason,
    ): void {
        if ($protection->broker_gtt_id && $protection->broker_status !== PositionProtection::BROKER_UNKNOWN) {
            try {
                $this->broker->cancelGtt((int) $profile->user_id, (string) $protection->broker_gtt_id);
            } catch (BrokerAmbiguousException) {
                $protection->forceFill([
                    'broker_status' => PositionProtection::BROKER_UNKNOWN,
                    'state' => PositionProtection::STATE_SYNCHRONIZING,
                    'last_error' => 'ambiguous_cancel',
                    'last_sync_reason' => $reason,
                    'last_broker_sync_at' => now(),
                ])->save();

                return;
            } catch (Throwable $e) {
                $protection->forceFill([
                    'last_error' => $e instanceof DomainException ? $e->errorCode() : 'cancel_failed',
                    'retry_count' => (int) $protection->retry_count + 1,
                ])->save();
                if ((int) $protection->retry_count >= self::MAX_RETRIES) {
                    $this->markNeedsAttention($protection, $protection->last_error ?: 'cancel_failed');
                }

                return;
            }
        } elseif ($protection->broker_status === PositionProtection::BROKER_UNKNOWN && $protection->broker_gtt_id) {
            $found = $this->broker->fetchGtt((int) $profile->user_id, (string) $protection->broker_gtt_id);
            if ($found && ! in_array($found->status, ['cancelled', 'rejected'], true)) {
                try {
                    $this->broker->cancelGtt((int) $profile->user_id, (string) $protection->broker_gtt_id);
                } catch (Throwable) {
                    $this->markNeedsAttention($protection, 'ambiguous_cancel');

                    return;
                }
            }
        }

        $this->markTerminal($protection, $terminalState, $reason);
    }

    public function clearForHolding(PortfolioProfile $profile, ?int $holdingId, int $stockId, string $ownerKey): void
    {
        $rows = PositionProtection::query()
            ->forProfile($profile)
            ->open()
            ->where('stock_id', $stockId)
            ->where('owner_key', $ownerKey)
            ->get();
        foreach ($rows as $row) {
            $this->cancelAtBroker($profile, $row, PositionProtection::STATE_RECONCILED, 'position_zero');
        }
    }

    protected function ensureRow(PortfolioProfile $profile, Holding $holding, string $type): PositionProtection
    {
        $open = $this->openForHolding($profile, $holding);
        if ($open) {
            $open->forceFill([
                'protection_type' => $type,
                'holding_id' => $holding->id,
                'strategy_id' => $holding->strategy_id,
                'owner_key' => $holding->owner_key ?: Holding::ownerKeyFor((int) $holding->strategy_id),
                'state' => $open->state === PositionProtection::STATE_CANCELLED
                    ? PositionProtection::STATE_PENDING
                    : $open->state,
            ])->save();

            return $open->fresh();
        }

        return PositionProtection::query()->create([
            'profile_id' => $profile->id,
            'holding_id' => $holding->id,
            'stock_id' => $holding->stock_id,
            'strategy_id' => $holding->strategy_id,
            'owner_key' => $holding->owner_key ?: Holding::ownerKeyFor((int) $holding->strategy_id),
            'protection_type' => $type,
            'state' => PositionProtection::STATE_PENDING,
            'quantity' => $holding->quantity,
            'retry_count' => 0,
            'sync_deferred' => false,
            'last_applied_fill_qty' => 0,
        ]);
    }

    protected function openForHolding(PortfolioProfile $profile, Holding $holding): ?PositionProtection
    {
        return PositionProtection::query()
            ->forProfile($profile)
            ->open()
            ->where('stock_id', $holding->stock_id)
            ->where('owner_key', $holding->owner_key ?: Holding::ownerKeyFor((int) $holding->strategy_id))
            ->orderByDesc('id')
            ->first();
    }

    protected function strategyHolding(PortfolioProfile $profile, int $holdingId): Holding
    {
        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('id', $holdingId)
            ->first();
        if (! $holding) {
            throw new DomainException('Holding not found for this portfolio.', 'NOT_FOUND', 404);
        }
        if ($holding->isUnmanaged()) {
            throw new DomainException('Advanced orders attach only to a Strategy position.', 'PROTECTION_NOT_STRATEGY', 422);
        }
        if ((float) $holding->quantity <= 0.0001) {
            throw new DomainException('Cannot protect a zero-quantity position.', 'PROTECTION_EMPTY_POSITION', 422);
        }

        return $holding;
    }

    protected function holdingFor(PositionProtection $protection): ?Holding
    {
        if ($protection->holding_id) {
            $row = Holding::query()
                ->where('profile_id', $protection->profile_id)
                ->where('id', $protection->holding_id)
                ->first();
            if ($row) {
                return $row;
            }
        }

        return Holding::query()
            ->where('profile_id', $protection->profile_id)
            ->where('stock_id', $protection->stock_id)
            ->where('owner_key', $protection->owner_key)
            ->first();
    }

    protected function markNeedsAttention(PositionProtection $protection, string $reason): void
    {
        $protection->forceFill([
            'state' => PositionProtection::STATE_NEEDS_ATTENTION,
            'last_error' => $reason,
            'needs_attention_at' => $protection->needs_attention_at ?? now(),
            'last_broker_sync_at' => now(),
        ])->save();
        $this->logger->event('PositionProtectionService', 'protection.needs_attention', 'warning', 'Protection needs attention', [
            'profile_id' => $protection->profile_id,
            'protection_id' => $protection->id,
            'reason' => $reason,
        ]);
    }

    protected function markTerminal(PositionProtection $protection, string $state, ?string $reason = null): void
    {
        $protection->forceFill([
            'state' => $state,
            'broker_status' => PositionProtection::BROKER_CANCELLED,
            'last_sync_reason' => $reason ?? $protection->last_sync_reason,
            'sync_deferred' => false,
            'last_broker_sync_at' => now(),
        ])->save();
    }

    protected function mapGttStatus(string $status): string
    {
        return match ($status) {
            'submitted' => PositionProtection::BROKER_SUBMITTED,
            'active' => PositionProtection::BROKER_ACTIVE,
            'triggered' => PositionProtection::BROKER_TRIGGERED,
            'cancelled' => PositionProtection::BROKER_CANCELLED,
            'rejected' => PositionProtection::BROKER_REJECTED,
            default => PositionProtection::BROKER_UNKNOWN,
        };
    }

    protected function attributionRecommendationId(PositionProtection $protection): ?int
    {
        return TradingRecommendation::query()
            ->where('profile_id', $protection->profile_id)
            ->where('security_id', $protection->stock_id)
            ->whereNotNull('strategy_version_id')
            ->orderByDesc('id')
            ->value('id');
    }

    protected function submissionKey(PositionProtection $protection, float $qty, float $price): string
    {
        return substr(hash('sha256', $protection->id.'|'.$protection->protection_type.'|'.$qty.'|'.round($price, 4)), 0, 40);
    }
}
