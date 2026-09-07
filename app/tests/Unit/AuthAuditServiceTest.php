<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthAuditService;
use App\Services\PortfolioLoggerService;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuthAuditServiceTest extends TestCase
{
    public function test_admin_force_logout_audit_uses_non_reusable_session_references(): void
    {
        $actor = new User(['email' => 'admin@example.com']);
        $actor->id = 7;
        $target = new User(['email' => 'investor@example.com']);
        $target->id = 19;
        $rawSessionId = 'secret-database-session-id';

        $logger = $this->createMock(PortfolioLoggerService::class);
        $logger->expects($this->once())
            ->method('security')
            ->with(
                'warning',
                'Administrator forced user logout',
                $this->callback(function (array $context) use ($rawSessionId): bool {
                    return $context['actor_user_id'] === 7
                        && $context['target_user_id'] === 19
                        && $context['scope'] === 'single'
                        && $context['affected_count'] === 1
                        && $context['session_references'] === [substr(hash('sha256', $rawSessionId), 0, 12)]
                        && ! in_array($rawSessionId, $context, true);
                }),
            );

        (new AuthAuditService($logger))->logAdminForceLogout(
            $actor,
            $target,
            Request::create('/api/users/19/sessions', 'DELETE'),
            'single',
            [$rawSessionId],
            1,
        );
    }
}
