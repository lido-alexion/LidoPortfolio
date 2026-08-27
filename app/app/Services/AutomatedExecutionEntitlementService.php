<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\PortfolioLoggerService;

class AutomatedExecutionEntitlementService
{
    public function __construct(
        protected PortfolioLoggerService $logger,
    ) {}

    public function setEntitled(User $actor, User $target, bool $entitled): User
    {
        if (! $actor->is_admin) {
            throw new DomainException('Admin access required.', 'FORBIDDEN', 403);
        }

        $target->forceFill([
            'automated_execution_entitled_at' => $entitled ? now() : null,
        ])->save();

        $this->logger->event('AutomatedExecutionEntitlementService', 'execution.entitlement_changed', 'info', 'Automated execution entitlement updated', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'entitled' => $entitled,
        ]);

        return $target->fresh();
    }
}
