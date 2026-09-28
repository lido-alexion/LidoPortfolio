<?php

namespace App\Services\AccessRequest;

use App\Models\AccessRequestVerification;
use Illuminate\Support\Str;

class AccessRequestVerificationService
{
    public function __construct(
        protected AccessRequestPolicyService $policy,
    ) {}

    public function purgeExpired(): int
    {
        return AccessRequestVerification::query()
            ->where(function ($query) {
                $query->where('expires_at', '<', now())
                    ->orWhereNotNull('used_at');
            })
            ->where('created_at', '<', now()->subDays((int) config('access_requests.verification_retention_days', 14)))
            ->delete();
    }

    /**
     * @return array{verification: AccessRequestVerification, raw_token: string}
     */
    public function create(string $fullName, string $email): array
    {
        $this->purgeExpired();

        $normalized = $this->policy->normalizeEmail($email);
        $rawToken = $this->generateRawToken();

        $verification = AccessRequestVerification::query()->create([
            'full_name' => trim($fullName),
            'email_normalized' => $normalized,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHours((int) config('access_requests.verification_expiry_hours', 24)),
        ]);

        return [
            'verification' => $verification,
            'raw_token' => $rawToken,
        ];
    }

    public function findValidByRawToken(string $rawToken): ?AccessRequestVerification
    {
        $this->purgeExpired();

        $verification = AccessRequestVerification::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($verification === null) {
            return null;
        }

        if ($verification->isUsed() || $verification->isExpired()) {
            return null;
        }

        return $verification;
    }

    public function markUsed(AccessRequestVerification $verification): void
    {
        $verification->used_at = now();
        $verification->save();
    }

    public function verificationUrl(string $rawToken): string
    {
        return rtrim((string) config('app.url'), '/').'/request-account/verify/'.$rawToken;
    }

    protected function generateRawToken(): string
    {
        do {
            $token = Str::random(64);
            $hash = hash('sha256', $token);
        } while (AccessRequestVerification::query()->where('token_hash', $hash)->exists());

        return $token;
    }
}
