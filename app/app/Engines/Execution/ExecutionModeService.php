<?php

namespace App\Engines\Execution;

use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Exceptions\DomainException;
use App\Models\PortfolioProfile;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\PortfolioLoggerService;
use App\Services\Security\TotpService;
use Illuminate\Support\Facades\DB;

class ExecutionModeService
{
    public function __construct(
        protected ExecutionGate $gate,
        protected TotpService $totp,
        protected PortfolioLoggerService $logger,
        protected RecommendationLifecycleService $recommendations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user, PortfolioProfile $profile): array
    {
        $this->gate->assertPortfolioOwner($user, $profile);
        $blockers = $this->gate->blockers($user, $profile);
        $mode = $profile->executionMode();
        $canLive = $blockers === [];

        return [
            'execution_mode' => $mode,
            'entitled' => $user->automatedExecutionEntitled(),
            'totp_enabled' => $user->totpIsActive(),
            'blockers' => $blockers,
            'can_submit_semi_automatic' => $canLive && $mode === PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC,
            'can_submit_automatic' => $canLive && $mode === PortfolioProfile::EXECUTION_MODE_AUTOMATIC,
        ];
    }

    public function changeMode(
        User $user,
        PortfolioProfile $profile,
        string $mode,
        bool $confirmAutomatic = false,
        #[\SensitiveParameter] ?string $totpCode = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ): PortfolioProfile {
        $this->gate->assertPortfolioOwner($user, $profile);

        if (! in_array($mode, PortfolioProfile::EXECUTION_MODES, true)) {
            throw new DomainException('Invalid execution mode.', 'EXECUTION_MODE_INVALID', 422);
        }

        $current = $profile->executionMode();
        if ($current === $mode) {
            return $profile;
        }

        if ($mode === PortfolioProfile::EXECUTION_MODE_MANUAL) {
            DB::transaction(function () use ($profile, $mode): void {
                $profile->forceFill(['execution_mode' => $mode])->save();
                $this->cancelUnsubmittedIntents($profile);
            });
            $this->logger->event('ExecutionModeService', 'execution.mode_changed', 'info', 'Execution mode set to manual', [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
                'from' => $current,
                'to' => $mode,
            ]);

            return $profile->fresh();
        }

        $this->gate->assertEntitled($user);

        if ($mode === PortfolioProfile::EXECUTION_MODE_AUTOMATIC
            && $current !== PortfolioProfile::EXECUTION_MODE_AUTOMATIC
            && ! $confirmAutomatic) {
            throw new DomainException(
                'Confirm switching this portfolio to Automatic execution.',
                'EXECUTION_AUTOMATIC_CONFIRM_REQUIRED',
                422,
            );
        }

        $this->totp->assertRecentVerification($user, $totpCode, $recoveryCode);

        DB::transaction(function () use ($profile, $mode, $current): void {
            $profile->forceFill(['execution_mode' => $mode])->save();
            if ($current === PortfolioProfile::EXECUTION_MODE_AUTOMATIC
                && $mode === PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC) {
                $this->invalidateAutomaticApprovals($profile);
            }
        });
        $this->logger->event('ExecutionModeService', 'execution.mode_changed', 'info', 'Execution mode changed', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'from' => $current,
            'to' => $mode,
        ]);

        return $profile->fresh();
    }

    protected function cancelUnsubmittedIntents(PortfolioProfile $profile): void
    {
        $rows = $this->mutableFeat039Intents($profile)->lockForUpdate()->get();
        foreach ($rows as $row) {
            $row->forceFill([
                'status' => TradingRecommendation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'mode_changed_to_manual',
            ])->save();
            $this->recommendations->releaseReservation($row);
        }
    }

    protected function invalidateAutomaticApprovals(PortfolioProfile $profile): void
    {
        $this->mutableFeat039Intents($profile)
            ->whereIn('status', [TradingRecommendation::STATUS_PENDING_EXECUTION, TradingRecommendation::STATUS_ACCEPTED])
            ->lockForUpdate()
            ->get()
            ->each(function (TradingRecommendation $row): void {
                $row->forceFill([
                    'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
                    'approved_at' => null,
                ])->save();
                $this->recommendations->releaseReservation($row);
            });
    }

    protected function mutableFeat039Intents(PortfolioProfile $profile)
    {
        return TradingRecommendation::query()
            ->forProfile($profile)
            ->whereNotNull('execution_anchor_date')
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ])
            ->whereDoesntHave('orders', fn ($query) => $query->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES));
    }
}
