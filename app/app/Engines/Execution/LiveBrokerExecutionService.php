<?php

namespace App\Engines\Execution;

use App\Engines\Recommendation\RecommendationEngine;
use App\Exceptions\DomainException;
use App\Models\ExecutionDecision;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\Broker\BrokerAmbiguousException;
use App\Services\Broker\BrokerGateway;
use App\Services\Broker\BrokerOrderRequest;
use App\Services\Broker\BrokerOrderSnapshot;
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\PortfolioLoggerService;
use Throwable;

/**
 * Broker submit + reconcile. Does not mark recommendations executed; that is
 * RecommendationEngine::markExecuted after an actual ledger fill (V4-FEAT-024).
 */
class LiveBrokerExecutionService
{
    public function __construct(
        protected ExecutionGate $gate,
        protected ExecutionEngine $execution,
        protected RecommendationEngine $recommendation,
        protected BrokerGateway $broker,
        protected RecommendationLendingCoordinator $lending,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @param  list<int>  $recommendationIds
     * @return list<array<string, mixed>>
     */
    public function submitSelected(
        User $user,
        PortfolioProfile $profile,
        array $recommendationIds,
        #[\SensitiveParameter] ?string $totpCode = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ): array {
        $this->gate->assertCanSubmitBroker($user, $profile, ExecutionGate::TRIGGER_SEMI, $totpCode, $recoveryCode);
        $ids = array_values(array_unique(array_map('intval', $recommendationIds)));
        if ($ids === []) {
            throw new DomainException('Select at least one recommendation.', 'VALIDATION_ERROR', 422);
        }

        $results = [];
        foreach ($ids as $id) {
            $results[] = $this->submitOne($user, $profile, $id, ExecutionGate::TRIGGER_SEMI);
        }

        return $results;
    }

    /**
     * Unattended Automatic path — enrolled TOTP is required; no per-order code.
     *
     * @return array{submitted:int,skipped:int,blocked:int,results:list<array<string,mixed>>}
     */
    public function submitAutomaticForProfile(PortfolioProfile $profile): array
    {
        $user = $profile->user ?? User::query()->find($profile->user_id);
        if (! $user) {
            return ['submitted' => 0, 'skipped' => 0, 'blocked' => 1, 'results' => []];
        }

        try {
            $this->gate->assertCanSubmitBroker($user, $profile, ExecutionGate::TRIGGER_AUTOMATIC);
        } catch (DomainException $e) {
            $this->logger->event('LiveBrokerExecutionService', 'execution.automatic_blocked', 'warning', 'Automatic submit blocked', [
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'reason' => $e->errorCode(),
            ]);

            return [
                'submitted' => 0,
                'skipped' => 0,
                'blocked' => 1,
                'results' => [['reason' => $e->errorCode(), 'message' => $e->getMessage()]],
            ];
        }

        $recs = TradingRecommendation::query()
            ->forProfile($profile)
            ->orderBy('id')
            ->get()
            ->filter(fn (TradingRecommendation $r) => $this->isAutomaticCandidate($r));

        $results = [];
        $submitted = 0;
        $skipped = 0;
        foreach ($recs as $rec) {
            $row = $this->submitOne($user, $profile, (int) $rec->id, ExecutionGate::TRIGGER_AUTOMATIC);
            $results[] = $row;
            if (($row['outcome'] ?? '') === ExecutionDecision::OUTCOME_SUBMITTED) {
                $submitted++;
            } else {
                $skipped++;
            }
        }

        return [
            'submitted' => $submitted,
            'skipped' => $skipped,
            'blocked' => 0,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitOne(User $user, PortfolioProfile $profile, int $recommendationId, string $trigger): array
    {
        $recommendation = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('id', $recommendationId)
            ->first();

        if (! $recommendation) {
            return $this->decisionRow($profile, $user, $recommendationId, $trigger, ExecutionDecision::OUTCOME_BLOCKED, 'not_found');
        }

        try {
            $recommendation = $this->ensurePendingExecution($user, $profile, $recommendation, $trigger);
        } catch (Throwable $e) {
            $reason = $e instanceof DomainException ? $e->errorCode() : 'prepare_failed';
            $this->recordDecision($profile, $user, $recommendation, $trigger, ExecutionDecision::OUTCOME_BLOCKED, $reason);

            return [
                'recommendation_id' => $recommendation->id,
                'outcome' => ExecutionDecision::OUTCOME_BLOCKED,
                'reason' => $reason,
            ];
        }

        if ($this->hasInFlightBrokerOrder($profile, $recommendation)) {
            $existing = $this->existingBrokerOrder($profile, $recommendation);
            if ($existing) {
                $this->reconcileOrder($profile, $existing);
            }

            return [
                'recommendation_id' => $recommendation->id,
                'outcome' => ExecutionDecision::OUTCOME_SKIPPED,
                'reason' => 'already_in_flight',
                'order_id' => $existing?->id,
            ];
        }

        $qty = $this->quantityFor($recommendation);
        $side = $recommendation->orderSide();
        if ($qty === null || $side === null) {
            $this->recordDecision($profile, $user, $recommendation, $trigger, ExecutionDecision::OUTCOME_SKIPPED, 'not_actionable');

            return [
                'recommendation_id' => $recommendation->id,
                'outcome' => ExecutionDecision::OUTCOME_SKIPPED,
                'reason' => 'not_actionable',
            ];
        }

        $submissionKey = $this->submissionKey($profile, $recommendation, $side, $qty);
        $existingByKey = TradingOrder::query()->where('submission_key', $submissionKey)->first();
        if ($existingByKey) {
            if ($existingByKey->hasInFlightBrokerOrder() || $existingByKey->broker_status === TradingOrder::BROKER_UNKNOWN) {
                $this->reconcileOrder($profile, $existingByKey);

                return [
                    'recommendation_id' => $recommendation->id,
                    'outcome' => ExecutionDecision::OUTCOME_SKIPPED,
                    'reason' => 'duplicate_prevented',
                    'order_id' => $existingByKey->id,
                ];
            }
            if (in_array($existingByKey->broker_status, [TradingOrder::BROKER_FILLED, TradingOrder::BROKER_REJECTED, TradingOrder::BROKER_CANCELLED], true)) {
                return [
                    'recommendation_id' => $recommendation->id,
                    'outcome' => ExecutionDecision::OUTCOME_SKIPPED,
                    'reason' => 'already_terminal',
                    'order_id' => $existingByKey->id,
                ];
            }
        }

        $stock = $recommendation->security ?? Stock::query()->find($recommendation->security_id);
        if (! $stock) {
            return $this->decisionRow($profile, $user, $recommendation->id, $trigger, ExecutionDecision::OUTCOME_BLOCKED, 'security_missing');
        }

        $decision = $this->recordDecision($profile, $user, $recommendation, $trigger, ExecutionDecision::OUTCOME_SUBMITTED, null);

        $order = $existingByKey && $existingByKey->broker_order_id === null && $existingByKey->status === TradingOrder::STATUS_PENDING
            ? $existingByKey
            : TradingOrder::query()->create([
                'profile_id' => $profile->id,
                'recommendation_id' => $recommendation->id,
                'security_id' => $stock->id,
                'side' => $side,
                'quantity' => $qty,
                'order_type' => 'market',
                'status' => TradingOrder::STATUS_PENDING,
                'broker_provider' => $this->broker->provider(),
                'broker_status' => TradingOrder::BROKER_UNKNOWN,
                'filled_quantity' => 0,
                'submission_key' => $submissionKey,
                'execution_decision_id' => $decision->id,
            ]);

        $request = new BrokerOrderRequest(
            userId: $user->id,
            profileId: $profile->id,
            recommendationId: $recommendation->id,
            symbol: (string) $stock->symbol,
            exchange: $stock->exchange ?: 'NSE',
            side: $side,
            quantity: $qty,
            submissionKey: $submissionKey,
        );

        try {
            $placed = $this->broker->placeOrder($request);
        } catch (BrokerAmbiguousException $e) {
            $order->forceFill([
                'broker_status' => TradingOrder::BROKER_UNKNOWN,
                'last_broker_sync_at' => now(),
            ])->save();
            $decision->forceFill([
                'outcome' => ExecutionDecision::OUTCOME_AMBIGUOUS,
                'reason' => 'ambiguous_place',
                'order_id' => $order->id,
            ])->save();
            $this->logger->event('LiveBrokerExecutionService', 'execution.broker_ambiguous', 'warning', 'Broker place result unknown; will not resubmit', [
                'profile_id' => $profile->id,
                'recommendation_id' => $recommendation->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
            ]);

            return [
                'recommendation_id' => $recommendation->id,
                'outcome' => ExecutionDecision::OUTCOME_AMBIGUOUS,
                'reason' => 'ambiguous_place',
                'order_id' => $order->id,
            ];
        } catch (DomainException $e) {
            $order->forceFill([
                'broker_status' => TradingOrder::BROKER_REJECTED,
                'status' => TradingOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'last_broker_sync_at' => now(),
            ])->save();
            $decision->forceFill([
                'outcome' => ExecutionDecision::OUTCOME_BLOCKED,
                'reason' => $e->errorCode(),
                'order_id' => $order->id,
            ])->save();

            return [
                'recommendation_id' => $recommendation->id,
                'outcome' => ExecutionDecision::OUTCOME_BLOCKED,
                'reason' => $e->errorCode(),
                'order_id' => $order->id,
            ];
        }

        $brokerStatus = $placed->status === 'rejected' ? TradingOrder::BROKER_REJECTED : TradingOrder::BROKER_SUBMITTED;
        $order->forceFill([
            'broker_order_id' => $placed->brokerOrderId,
            'broker_status' => $brokerStatus,
            'last_broker_sync_at' => now(),
        ])->save();
        if ($brokerStatus === TradingOrder::BROKER_REJECTED) {
            $order->forceFill([
                'status' => TradingOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();
        }
        $decision->forceFill(['order_id' => $order->id])->save();

        $this->logger->event('LiveBrokerExecutionService', 'execution.broker_submitted', 'info', 'Broker order submitted', [
            'profile_id' => $profile->id,
            'recommendation_id' => $recommendation->id,
            'order_id' => $order->id,
            'broker_order_id' => $placed->brokerOrderId,
            'user_id' => $user->id,
            'trigger' => $trigger,
        ]);

        if ($brokerStatus === TradingOrder::BROKER_SUBMITTED) {
            $this->reconcileOrder($profile, $order->fresh());
        }

        return [
            'recommendation_id' => $recommendation->id,
            'outcome' => $brokerStatus === TradingOrder::BROKER_REJECTED
                ? ExecutionDecision::OUTCOME_BLOCKED
                : ExecutionDecision::OUTCOME_SUBMITTED,
            'order_id' => $order->id,
            'broker_order_id' => $placed->brokerOrderId,
            'broker_status' => $order->fresh()->broker_status,
        ];
    }

    public function reconcileOrder(PortfolioProfile $profile, TradingOrder $order): TradingOrder
    {
        if ((int) $order->profile_id !== (int) $profile->id) {
            throw new DomainException('Order not found for this portfolio.', 'NOT_FOUND', 404);
        }
        if (! $order->broker_order_id) {
            return $order;
        }

        $snapshot = $this->broker->fetchOrder((int) $profile->user_id, (string) $order->broker_order_id);
        if (! $snapshot) {
            $order->forceFill(['last_broker_sync_at' => now()])->save();

            return $order->fresh();
        }

        return $this->applySnapshot($profile, $order, $snapshot);
    }

    public function reconcileOpenForProfile(PortfolioProfile $profile): int
    {
        $orders = TradingOrder::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('broker_order_id')
            ->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)
            ->get();

        $n = 0;
        foreach ($orders as $order) {
            $this->reconcileOrder($profile, $order);
            $n++;
        }

        return $n;
    }

    public function applySnapshot(PortfolioProfile $profile, TradingOrder $order, BrokerOrderSnapshot $snapshot): TradingOrder
    {
        $mapped = $this->mapBrokerStatus($snapshot->status);
        $order->forceFill([
            'broker_status' => $mapped,
            'filled_quantity' => $snapshot->filledQuantity,
            'average_fill_price' => $snapshot->averagePrice,
            'last_broker_sync_at' => now(),
        ])->save();

        $target = (float) $order->quantity;
        $filled = (float) $snapshot->filledQuantity;
        $terminalFilled = in_array($mapped, [TradingOrder::BROKER_FILLED], true)
            || ($filled + 0.0001 >= $target && $target > 0);
        $terminalUnfilled = in_array($mapped, [TradingOrder::BROKER_REJECTED, TradingOrder::BROKER_CANCELLED], true);

        if ($terminalFilled) {
            $this->execution->applyBrokerFill($profile, $order->fresh(), $filled, (float) ($snapshot->averagePrice ?? 0));
        } elseif ($terminalUnfilled && $filled > 0.0001) {
            $this->execution->applyBrokerFill($profile, $order->fresh(), $filled, (float) ($snapshot->averagePrice ?? 0), completeRecommendation: false);
            if ($order->fresh()->status === TradingOrder::STATUS_PENDING) {
                $order->forceFill([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ])->save();
            }
        } elseif ($terminalUnfilled) {
            $order->forceFill([
                'status' => TradingOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();
        }

        $this->logger->event('LiveBrokerExecutionService', 'execution.broker_reconciled', 'info', 'Broker order reconciled', [
            'profile_id' => $profile->id,
            'order_id' => $order->id,
            'recommendation_id' => $order->recommendation_id,
            'broker_order_id' => $order->broker_order_id,
            'broker_status' => $mapped,
            'filled_quantity' => $filled,
        ]);

        return $order->fresh(['security', 'recommendation']);
    }

    protected function ensurePendingExecution(
        User $user,
        PortfolioProfile $profile,
        TradingRecommendation $recommendation,
        string $trigger,
    ): TradingRecommendation {
        if ($recommendation->canExecuteManually()) {
            $this->lending->assertCanExecute($recommendation);

            return $recommendation;
        }

        if ($trigger === ExecutionGate::TRIGGER_AUTOMATIC
            && $recommendation->isActionable()
            && $recommendation->canBeReviewed()) {
            return $this->recommendation->recordReview(
                $profile,
                $user,
                $recommendation,
                TradingRecommendation::DECISION_APPROVED,
                'Automatic execution: internal approval',
            );
        }

        throw new DomainException(
            'Recommendation is not eligible for broker submission (status: '.$recommendation->status.').',
            'RECOMMENDATION_NOT_ELIGIBLE',
            422,
        );
    }

    protected function isAutomaticCandidate(TradingRecommendation $r): bool
    {
        if (! $r->isActionable()) {
            return false;
        }
        if ($r->canExecuteManually()) {
            return true;
        }

        return $r->canBeReviewed();
    }

    protected function hasInFlightBrokerOrder(PortfolioProfile $profile, TradingRecommendation $recommendation): bool
    {
        return $this->existingBrokerOrder($profile, $recommendation)?->hasInFlightBrokerOrder() ?? false;
    }

    protected function existingBrokerOrder(PortfolioProfile $profile, TradingRecommendation $recommendation): ?TradingOrder
    {
        return TradingOrder::query()
            ->where('profile_id', $profile->id)
            ->where('recommendation_id', $recommendation->id)
            ->whereNotNull('broker_order_id')
            ->orderByDesc('id')
            ->first();
    }

    protected function quantityFor(TradingRecommendation $recommendation): ?float
    {
        $qty = $recommendation->suggestedQuantity();
        if ($qty === null || $qty <= 0) {
            return null;
        }

        return (float) max(1, (int) round($qty));
    }

    protected function submissionKey(PortfolioProfile $profile, TradingRecommendation $recommendation, string $side, float $qty): string
    {
        return substr(hash('sha256', $profile->id.'|'.$recommendation->id.'|'.$side.'|'.$qty), 0, 40);
    }

    protected function mapBrokerStatus(string $status): string
    {
        return match ($status) {
            'submitted' => TradingOrder::BROKER_SUBMITTED,
            'open' => TradingOrder::BROKER_OPEN,
            'partial' => TradingOrder::BROKER_PARTIAL,
            'filled' => TradingOrder::BROKER_FILLED,
            'rejected' => TradingOrder::BROKER_REJECTED,
            'cancelled' => TradingOrder::BROKER_CANCELLED,
            default => TradingOrder::BROKER_UNKNOWN,
        };
    }

    protected function recordDecision(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        string $trigger,
        string $outcome,
        ?string $reason,
    ): ExecutionDecision {
        return ExecutionDecision::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $recommendation->id,
            'user_id' => $user->id,
            'mode' => $profile->executionMode(),
            'trigger' => $trigger,
            'outcome' => $outcome,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decisionRow(
        PortfolioProfile $profile,
        User $user,
        int $recommendationId,
        string $trigger,
        string $outcome,
        string $reason,
    ): array {
        return [
            'recommendation_id' => $recommendationId,
            'outcome' => $outcome,
            'reason' => $reason,
        ];
    }
}
