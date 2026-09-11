<?php

namespace App\Services\Execution;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Exceptions\DomainException;
use App\Models\ExecutionSafetyEvent;
use App\Models\PortfolioProfile;
use App\Models\TradingOrder;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Broker\BrokerGateway;
use App\Services\PortfolioLoggerService;
use App\Services\Security\TotpService;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecutionSafetyService
{
    public function __construct(
        protected BrokerGateway $broker,
        protected BrokerConnectionService $connections,
        protected LiveBrokerExecutionService $liveBroker,
        protected TotpService $totp,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(User $user): array
    {
        return [
            'execution_state' => $user->executionState(),
            'is_halted' => $user->executionIsHalted(),
            'halted_at' => $user->execution_halted_at?->toIso8601String(),
            'halt_reason' => $user->execution_halt_reason,
            'recovered_at' => $user->execution_recovered_at?->toIso8601String(),
            'live_quote_policy' => $user->liveQuotePolicy(),
            'broker' => $this->connections->status($user),
        ];
    }

    public function halt(User $user, ?User $actor = null, ?string $reason = null, string $event = 'execution.halted'): User
    {
        return DB::transaction(function () use ($user, $actor, $reason, $event): User {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            $fresh->forceFill([
                'execution_state' => User::EXECUTION_STATE_EMERGENCY_HALT,
                'execution_halted_at' => $fresh->execution_halted_at ?? now(),
                'execution_halted_by' => $actor?->id ?? $user->id,
                'execution_halt_reason' => $reason,
            ])->save();

            $this->record($fresh, $actor ?? $fresh, $event, 'completed', ['reason' => $reason]);

            return $fresh->fresh();
        });
    }

    public function recover(
        User $user,
        User $actor,
        bool $confirm,
        #[\SensitiveParameter] ?string $totpCode = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ): User {
        if (! $confirm) {
            throw new DomainException('Recovery requires explicit confirmation.', 'RECOVERY_CONFIRMATION_REQUIRED', 422);
        }
        if (! $user->totpIsActive()) {
            throw new DomainException('Authenticator verification is required before recovery.', 'TOTP_REQUIRED', 403);
        }
        $this->totp->assertRecentVerification($user, $totpCode, $recoveryCode);

        return DB::transaction(function () use ($user, $actor): User {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            $fresh->forceFill([
                'execution_state' => User::EXECUTION_STATE_NORMAL,
                'execution_recovered_at' => now(),
            ])->save();

            $this->record($fresh, $actor, 'execution.recovered', 'completed');

            return $fresh->fresh();
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function disconnect(User $user, User $actor, ?string $reason = null): array
    {
        $halted = $this->halt($user, $actor, $reason, 'execution.kite_disconnect_halt');
        $this->connections->disconnect($halted);
        $this->record($halted, $actor, 'execution.kite_disconnected', 'completed', ['reason' => $reason]);

        return $this->snapshot($halted->fresh());
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelOpenOrdersAndDisconnect(User $user, User $actor, ?string $reason = null): array
    {
        $halted = $this->halt($user, $actor, $reason, 'execution.emergency_cancel_halt');
        $orders = TradingOrder::query()
            ->whereIn('profile_id', PortfolioProfile::query()->where('user_id', $halted->id)->select('id'))
            ->whereNotNull('broker_order_id')
            ->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)
            ->where(function ($query): void {
                $query->whereNull('order_type')->orWhere('order_type', '!=', 'gtt_protection');
            })
            ->orderBy('id')
            ->get();

        $cancelled = 0;
        $failed = [];
        foreach ($orders as $order) {
            try {
                $snapshot = $this->broker->cancelOrder($halted->id, (string) $order->broker_order_id);
                $profile = PortfolioProfile::query()->find($order->profile_id);
                if ($profile) {
                    $this->liveBroker->applySnapshot($profile, $order, $snapshot);
                }
                $cancelled++;
            } catch (Throwable $e) {
                $failed[] = [
                    'order_id' => $order->id,
                    'broker_order_id' => $order->broker_order_id,
                    'reason' => $e instanceof DomainException ? $e->errorCode() : 'cancel_failed',
                ];
            }
        }

        $this->connections->disconnect($halted);
        $status = $failed === [] ? 'completed' : 'partial';
        $this->record($halted, $actor, 'execution.emergency_cancel_disconnect', $status, [
            'reason' => $reason,
            'eligible_orders' => $orders->count(),
            'cancelled_orders' => $cancelled,
            'failed' => $failed,
        ]);

        return [
            ...$this->snapshot($halted->fresh()),
            'eligible_orders' => $orders->count(),
            'cancelled_orders' => $cancelled,
            'failed' => $failed,
        ];
    }

    public function updateQuotePolicy(User $user, string $policy): User
    {
        if (! in_array($policy, [User::LIVE_QUOTE_POLICY_STRICT, User::LIVE_QUOTE_POLICY_ALLOW_CLOSE_FALLBACK], true)) {
            throw new DomainException('Unsupported quote policy.', 'VALIDATION_ERROR', 422);
        }

        $user->forceFill(['live_quote_policy' => $policy])->save();
        $this->record($user->fresh(), $user, 'execution.quote_policy_updated', 'completed', ['policy' => $policy]);

        return $user->fresh();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function record(User $user, ?User $actor, string $event, string $status, array $context = []): void
    {
        ExecutionSafetyEvent::query()->create([
            'user_id' => $user->id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'provider' => $this->broker->provider(),
            'status' => $status,
            'context' => $context,
            'created_at' => now(),
        ]);

        $this->logger->event('ExecutionSafetyService', $event, $status === 'completed' ? 'warning' : 'error', 'Execution safety event', [
            'user_id' => $user->id,
            'actor_user_id' => $actor?->id,
            'status' => $status,
            ...$context,
        ]);
    }
}
