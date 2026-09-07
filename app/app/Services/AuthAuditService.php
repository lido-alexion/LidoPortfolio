<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class AuthAuditService
{
    public function __construct(protected PortfolioLoggerService $logger) {}

    public function logLoginSuccess(User $user, Request $request): void
    {
        $this->logger->security('info', 'User login successful', $this->context($user, $request));
    }

    public function logLoginFailure(Request $request, ?string $email = null): void
    {
        $this->logger->security('warning', 'User login failed', [
            'email' => $this->maskEmail($email ?? $request->input('email')),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    public function logLogout(User $user, Request $request, string $scope = 'current'): void
    {
        $this->logger->security('info', 'User logout', [
            ...$this->context($user, $request),
            'scope' => $scope,
        ]);
    }

    public function logSuspicious(Request $request, string $reason): void
    {
        $this->logger->security('warning', 'Suspicious authentication activity', [
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Record FEAT-012 administrative session revocation without persisting a
     * reusable session identifier or any authentication secret.
     *
     * @param  array<int, string>  $sessionIds
     */
    public function logAdminForceLogout(
        User $actor,
        User $target,
        Request $request,
        string $scope,
        array $sessionIds,
        int $affectedCount,
    ): void {
        $this->logger->security('warning', 'Administrator forced user logout', [
            ...$this->context($actor, $request),
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'scope' => $scope,
            'affected_count' => $affectedCount,
            'session_references' => array_map(
                static fn (string $id): string => substr(hash('sha256', $id), 0, 12),
                $sessionIds,
            ),
            'occurred_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(User $user, Request $request): array
    {
        return [
            'user_id' => $user->id,
            'email' => $this->maskEmail($user->email),
            'ip_address' => $request->ip(),
            'session_id' => $request->hasSession()
                ? substr($request->session()->getId(), 0, 8).'…'
                : null,
        ];
    }

    protected function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));

        return $visible.'***@'.$domain;
    }
}
