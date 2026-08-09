<?php

namespace App\Services;

use App\Models\PasswordResetLink;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkService
{
    public const EXPIRY_HOURS = 72;

    public function purgeExpired(): int
    {
        return PasswordResetLink::query()
            ->whereNull('used_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    public function pendingForUser(int $userId): ?PasswordResetLink
    {
        $this->purgeExpired();

        return PasswordResetLink::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->latest('id')
            ->first();
    }

    public function findByToken(string $token): ?PasswordResetLink
    {
        $this->purgeExpired();

        $link = PasswordResetLink::query()
            ->with('user:id,name,email')
            ->where('token', $token)
            ->first();

        if ($link === null) {
            return null;
        }

        if ($link->isUsed()) {
            return $link;
        }

        if ($link->isExpired()) {
            $link->delete();

            return null;
        }

        return $link;
    }

    public function create(User $admin, User $targetUser): PasswordResetLink
    {
        PasswordResetLink::query()
            ->where('user_id', $targetUser->id)
            ->whereNull('used_at')
            ->where('expires_at', '<', now())
            ->delete();

        if ($this->pendingForUser($targetUser->id) !== null) {
            throw ValidationException::withMessages([
                'user_id' => ['A pending password reset link already exists for this user. Regenerate or revoke it first.'],
            ]);
        }

        return PasswordResetLink::query()->create([
            'user_id' => $targetUser->id,
            'token' => $this->generateToken(),
            'created_by_user_id' => $admin->id,
            'expires_at' => now()->addHours(self::EXPIRY_HOURS),
        ]);
    }

    public function regenerate(PasswordResetLink $link): PasswordResetLink
    {
        if ($link->isUsed()) {
            throw ValidationException::withMessages([
                'link' => ['This password reset link was already used.'],
            ]);
        }

        $link->token = $this->generateToken();
        $link->expires_at = now()->addHours(self::EXPIRY_HOURS);
        $link->save();

        return $link->fresh(['user:id,name,email', 'createdBy:id,name,email']);
    }

    public function revoke(PasswordResetLink $link): void
    {
        if ($link->isUsed()) {
            throw ValidationException::withMessages([
                'link' => ['Used password reset links cannot be revoked.'],
            ]);
        }

        $link->delete();
    }

    public function accept(string $token, string $password): User
    {
        $link = $this->findByToken($token);

        if ($link === null) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired. Please contact your administrator for a new link.'],
            ]);
        }

        if ($link->isUsed()) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link was already used. You can sign in with your new password.'],
            ]);
        }

        $user = $link->user;
        if ($user === null) {
            $link->delete();
            throw ValidationException::withMessages([
                'token' => ['This account no longer exists.'],
            ]);
        }

        $user->password = Hash::make($password);
        $user->save();

        $link->used_at = now();
        $link->save();

        return $user->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $this->purgeExpired();

        return PasswordResetLink::query()
            ->with(['user:id,name,email', 'createdBy:id,name,email'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PasswordResetLink $link) => $this->toAdminPayload($link))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminPayload(PasswordResetLink $link): array
    {
        $status = $link->isUsed()
            ? 'used'
            : ($link->isExpired() ? 'expired' : 'pending');

        $resetUrl = $this->resetUrl($link->token);

        return [
            'id' => $link->id,
            'user_id' => $link->user_id,
            'email' => $link->user?->email,
            'user_name' => $link->user?->name,
            'status' => $status,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'used_at' => $link->used_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            'created_by' => $link->createdBy?->only(['id', 'name', 'email']),
            'reset_url' => $status === 'pending' ? $resetUrl : null,
            'reset_message' => $status === 'pending' ? $this->composeResetMessage($link) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicPayload(PasswordResetLink $link): array
    {
        return [
            'email' => $link->user?->email,
            'name' => $link->user?->name,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'status' => $link->isUsed() ? 'used' : 'pending',
        ];
    }

    public function resetUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/reset-password/'.$token;
    }

    public function composeResetMessage(PasswordResetLink $link): string
    {
        $url = $this->resetUrl($link->token);
        $expires = $link->expires_at?->timezone(config('app.timezone', 'UTC'))->format('D, M j, Y g:i A T');
        $appName = config('app.name', 'Lido Portfolio');
        $email = $link->user?->email ?? 'your account';

        return <<<TEXT
Subject: Reset your {$appName} password

Hello,

An administrator created a password reset link for {$email}. Use the link below to set a new password (no current password required):

{$url}

This link expires on {$expires} (72 hours from when it was sent).

If you did not request this, contact your administrator.

Thank you.
TEXT;
    }

    protected function generateToken(): string
    {
        do {
            $token = Str::random(64);
            $inviteHash = hash('sha256', $token);
        } while (
            PasswordResetLink::query()->where('token', $token)->exists()
            || \App\Models\UserInvite::query()->where('token', $inviteHash)->exists()
        );

        return $token;
    }
}
