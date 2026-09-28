<?php

namespace App\Services\AccessRequest;

use App\Models\AccessRequest;
use App\Models\AccessRequestBan;
use App\Models\User;
use App\Services\UserInviteService;
use Illuminate\Support\Str;

class AccessRequestPolicyService
{
    public function __construct(
        protected UserInviteService $invites,
    ) {}

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function canStartVerification(string $email): array
    {
        $normalized = $this->normalizeEmail($email);

        if (User::query()->where('email', $normalized)->exists()) {
            return ['allowed' => false, 'reason' => 'existing_user'];
        }

        if ($this->invites->pendingForEmail($normalized) !== null) {
            return ['allowed' => false, 'reason' => 'pending_invite'];
        }

        if ($this->activeBan($normalized) !== null) {
            return ['allowed' => false, 'reason' => 'banned'];
        }

        if ($this->pendingRequest($normalized) !== null) {
            return ['allowed' => false, 'reason' => 'pending_request'];
        }

        if ($this->withinIgnoreCooldown($normalized)) {
            return ['allowed' => false, 'reason' => 'cooldown'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function canCreatePendingRequest(string $email): array
    {
        return $this->canStartVerification($email);
    }

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function canIssueInvitationForEmail(string $email): array
    {
        $normalized = $this->normalizeEmail($email);

        if (User::query()->where('email', $normalized)->exists()) {
            return ['allowed' => false, 'reason' => 'existing_user'];
        }

        if ($this->invites->pendingForEmail($normalized) !== null) {
            return ['allowed' => false, 'reason' => 'pending_invite'];
        }

        if ($this->activeBan($normalized) !== null) {
            return ['allowed' => false, 'reason' => 'banned'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function activeBan(string $normalizedEmail): ?AccessRequestBan
    {
        return AccessRequestBan::query()
            ->where('email_normalized', $normalizedEmail)
            ->whereNull('cleared_at')
            ->latest('id')
            ->first();
    }

    public function pendingRequest(string $normalizedEmail): ?AccessRequest
    {
        return AccessRequest::query()
            ->where('email_normalized', $normalizedEmail)
            ->where('status', AccessRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    public function withinIgnoreCooldown(string $normalizedEmail): bool
    {
        $latestIgnored = AccessRequest::query()
            ->where('email_normalized', $normalizedEmail)
            ->where('status', AccessRequest::STATUS_IGNORED)
            ->latest('id')
            ->first();

        if ($latestIgnored === null || $latestIgnored->resubmit_allowed_after === null) {
            return false;
        }

        return $latestIgnored->resubmit_allowed_after->isFuture();
    }
}
