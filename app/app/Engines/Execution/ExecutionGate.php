<?php

namespace App\Engines\Execution;

use App\Exceptions\DomainException;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Security\TotpService;

/**
 * Server-side gates for Semi-Automatic / Automatic broker submission (V4-SPEC-007).
 */
class ExecutionGate
{
    public const TRIGGER_SEMI = 'semi_automatic';

    public const TRIGGER_AUTOMATIC = 'automatic';

    public function __construct(
        protected TotpService $totp,
        protected BrokerConnectionService $brokerConnections,
    ) {}

    public function assertPortfolioOwner(User $user, PortfolioProfile $profile): void
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw new DomainException('Portfolio does not belong to this user.', 'PORTFOLIO_ACCESS_DENIED', 403);
        }
    }

    public function assertEntitled(User $user): void
    {
        if (! $user->automatedExecutionEntitled()) {
            throw new DomainException(
                'Automated broker execution is not enabled for this account.',
                'EXECUTION_NOT_ENTITLED',
                403,
            );
        }
    }

    /**
     * @param  self::TRIGGER_*  $trigger
     */
    public function assertCanSubmitBroker(
        User $user,
        PortfolioProfile $profile,
        string $trigger,
        #[\SensitiveParameter] ?string $totpCode = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ): void {
        $this->assertPortfolioOwner($user, $profile);
        $this->assertEntitled($user);

        $mode = $profile->executionMode();
        if ($mode === PortfolioProfile::EXECUTION_MODE_MANUAL) {
            throw new DomainException(
                'Manual mode does not submit orders to the broker.',
                'EXECUTION_MODE_MANUAL',
                403,
            );
        }

        if ($trigger === self::TRIGGER_SEMI && $mode !== PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC) {
            throw new DomainException(
                'This portfolio is not in Semi-Automatic mode.',
                'EXECUTION_MODE_MISMATCH',
                403,
            );
        }
        if ($trigger === self::TRIGGER_AUTOMATIC && $mode !== PortfolioProfile::EXECUTION_MODE_AUTOMATIC) {
            throw new DomainException(
                'This portfolio is not in Automatic mode.',
                'EXECUTION_MODE_MISMATCH',
                403,
            );
        }

        if (! $user->totpIsActive()) {
            throw new DomainException(
                'Authenticator must be enrolled before automated broker submission.',
                'TOTP_REQUIRED',
                403,
            );
        }

        if ($trigger === self::TRIGGER_SEMI) {
            $this->totp->assertRecentVerification($user, $totpCode, $recoveryCode);
        }

        $status = $this->brokerConnections->status($user);
        if (! ($status['usable'] ?? false)) {
            $code = ($status['connected'] ?? false) ? 'BROKER_SESSION_EXPIRED' : 'BROKER_NOT_CONNECTED';
            throw new DomainException(
                $code === 'BROKER_SESSION_EXPIRED'
                    ? 'Zerodha session expired. Connect Kite again.'
                    : 'Connect your Zerodha account before submitting broker orders.',
                $code,
                403,
            );
        }
    }

    /**
     * @return list<string>
     */
    public function blockers(User $user, PortfolioProfile $profile): array
    {
        $blockers = [];
        if ((int) $profile->user_id !== (int) $user->id) {
            $blockers[] = 'portfolio_access';
        }
        if (! $user->automatedExecutionEntitled()) {
            $blockers[] = 'entitlement';
        }
        if (! $user->totpIsActive()) {
            $blockers[] = 'totp';
        }
        $broker = $this->brokerConnections->status($user);
        if (! ($broker['usable'] ?? false)) {
            $blockers[] = ($broker['connected'] ?? false) ? 'broker_session' : 'broker';
        }

        return $blockers;
    }
}
