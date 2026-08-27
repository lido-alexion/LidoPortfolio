<?php

namespace App\Engines\Execution;

use App\Exceptions\DomainException;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\PortfolioLoggerService;
use App\Services\Security\TotpService;

class ExecutionModeService
{
    public function __construct(
        protected ExecutionGate $gate,
        protected TotpService $totp,
        protected PortfolioLoggerService $logger,
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
            $profile->forceFill(['execution_mode' => $mode])->save();
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

        $profile->forceFill(['execution_mode' => $mode])->save();
        $this->logger->event('ExecutionModeService', 'execution.mode_changed', 'info', 'Execution mode changed', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'from' => $current,
            'to' => $mode,
        ]);

        return $profile->fresh();
    }
}
