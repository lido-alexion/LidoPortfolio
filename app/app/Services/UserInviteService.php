<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserInviteService
{
    public const EXPIRY_HOURS = 72;

    public function __construct(
        protected PortfolioProfileService $portfolios,
    ) {}

    public function purgeExpired(): int
    {
        return UserInvite::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    public function pendingForEmail(string $email): ?UserInvite
    {
        $this->purgeExpired();

        return UserInvite::query()
            ->where('email', $this->normalizeEmail($email))
            ->whereNull('accepted_at')
            ->where('expires_at', '>=', now())
            ->latest('id')
            ->first();
    }

    public function findByToken(string $rawToken): ?UserInvite
    {
        $this->purgeExpired();

        $invite = UserInvite::query()
            ->where('token', $this->hashToken($rawToken))
            ->first();

        if ($invite === null) {
            return null;
        }

        if ($invite->isAccepted()) {
            return $invite;
        }

        if ($invite->isExpired()) {
            $invite->delete();

            return null;
        }

        return $invite;
    }

    /**
     * @return array{invite: UserInvite, raw_token: string}
     */
    public function create(User $admin, string $email): array
    {
        $email = $this->normalizeEmail($email);

        UserInvite::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['An account with this email already exists.'],
            ]);
        }

        if ($this->pendingForEmail($email) !== null) {
            throw ValidationException::withMessages([
                'email' => ['A pending invite already exists for this email. Regenerate or revoke it first.'],
            ]);
        }

        $rawToken = $this->generateRawToken();

        $invite = UserInvite::query()->create([
            'email' => $email,
            'token' => $this->hashToken($rawToken),
            'invited_by_user_id' => $admin->id,
            'expires_at' => now()->addHours(self::EXPIRY_HOURS),
        ]);

        return [
            'invite' => $invite,
            'raw_token' => $rawToken,
        ];
    }

    /**
     * Rotate the invitation bearer credential. Does not extend expires_at.
     *
     * @return array{invite: UserInvite, raw_token: string}
     */
    public function regenerate(UserInvite $invite): array
    {
        if ($invite->isAccepted()) {
            throw ValidationException::withMessages([
                'invite' => ['This invite was already accepted.'],
            ]);
        }

        $rawToken = $this->generateRawToken();
        $invite->token = $this->hashToken($rawToken);
        $invite->save();

        return [
            'invite' => $invite->fresh(),
            'raw_token' => $rawToken,
        ];
    }

    public function revoke(UserInvite $invite): void
    {
        if ($invite->isAccepted()) {
            throw ValidationException::withMessages([
                'invite' => ['Accepted invites cannot be revoked.'],
            ]);
        }

        $invite->delete();
    }

    /**
     * @return array{user: User, invite: UserInvite}
     */
    public function accept(string $rawToken, string $name, string $password): array
    {
        $invite = $this->findByToken($rawToken);

        if ($invite === null) {
            throw ValidationException::withMessages([
                'token' => ['This invite link is invalid or has expired. Please contact your administrator for a new invite.'],
            ]);
        }

        if ($invite->isAccepted()) {
            throw ValidationException::withMessages([
                'token' => ['This invite was already used. You can sign in with your password.'],
            ]);
        }

        if (User::query()->where('email', $invite->email)->exists()) {
            $invite->delete();
            throw ValidationException::withMessages([
                'token' => ['An account already exists for this email. Try signing in.'],
            ]);
        }

        $displayName = trim($name) !== '' ? trim($name) : Str::before($invite->email, '@');

        $user = User::query()->create([
            'name' => $displayName,
            'email' => $invite->email,
            'password' => Hash::make($password),
        ]);

        $this->portfolios->createDefaultForUser($user);

        $invite->accepted_at = now();
        $invite->user_id = $user->id;
        $invite->save();

        return [
            'user' => $user,
            'invite' => $invite->fresh(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        return UserInvite::query()
            ->with('invitedBy:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserInvite $invite) => $this->toAdminPayload($invite))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminPayload(UserInvite $invite, ?string $rawToken = null): array
    {
        $status = $invite->isAccepted()
            ? 'accepted'
            : ($invite->isExpired() ? 'expired' : 'pending');

        $includeUrl = $status === 'pending' && $rawToken !== null && $rawToken !== '';

        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'status' => $status,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'accepted_at' => $invite->accepted_at?->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
            'invited_by' => $invite->invitedBy?->only(['id', 'name', 'email']),
            'invite_url' => $includeUrl ? $this->inviteUrl($rawToken) : null,
            'invite_message' => $includeUrl ? $this->composeInviteMessage($invite, $rawToken) : null,
            'url_available' => $includeUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicPayload(UserInvite $invite): array
    {
        return [
            'email' => $invite->email,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'status' => $invite->isAccepted() ? 'accepted' : 'pending',
        ];
    }

    public function inviteUrl(string $rawToken): string
    {
        return rtrim((string) config('app.url'), '/').'/invite/'.$rawToken;
    }

    public function composeInviteMessage(UserInvite $invite, string $rawToken): string
    {
        $url = $this->inviteUrl($rawToken);
        $expires = $invite->expires_at?->timezone(config('app.timezone', 'UTC'))->format('D, M j, Y g:i A T');
        $appName = config('app.name', 'Lido Portfolio');

        return <<<TEXT
Subject: You're invited to {$appName}

Hello,

You've been invited to join {$appName}. Use the link below to set your password and sign in:

{$url}

This link expires on {$expires} (72 hours from when the invitation was created).

If it has expired or you no longer have the link, contact your administrator for a new invitation URL.

Thank you.
TEXT;
    }

    public function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    protected function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    protected function generateRawToken(): string
    {
        do {
            $token = Str::random(64);
            $hash = $this->hashToken($token);
        } while (UserInvite::query()->where('token', $hash)->exists());

        return $token;
    }
}
