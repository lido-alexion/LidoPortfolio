<?php

namespace App\Services\AccessRequest;

use App\Models\AccessRequestVerification;
use App\Models\AccessRequest;
use App\Models\AccessRequestAuditEvent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AccessRequestVerificationService
{
    public function __construct(
        protected AccessRequestPolicyService $policy,
    ) {}

    public function purgeExpired(): int
    {
        $this->expireUnverifiedRequests();

        return AccessRequestVerification::query()
            ->where(function ($query) {
                $query->where('expires_at', '<', now())
                    ->orWhereNotNull('used_at');
            })
            ->where('created_at', '<', now()->subDays((int) config('access_requests.verification_retention_days', 14)))
            ->delete();
    }

    public function expireUnverifiedRequests(): int
    {
        $expired = 0;
        AccessRequest::query()
            ->where('status', AccessRequest::STATUS_PENDING)
            ->where('verification_status', AccessRequest::VERIFICATION_UNVERIFIED)
            ->where('created_at', '<=', now()->subDays(7))
            ->orderBy('id')->chunkById(100, function ($requests) use (&$expired): void {
                foreach ($requests as $request) {
                    $now = now();
                    $updated = DB::transaction(function () use ($request, $now): int {
                        $updated = AccessRequest::query()->whereKey($request->id)
                            ->where('status', AccessRequest::STATUS_PENDING)
                            ->where('verification_status', AccessRequest::VERIFICATION_UNVERIFIED)
                            ->update([
                                'status' => AccessRequest::STATUS_EXPIRED,
                                'expires_at' => $request->created_at->copy()->addDays(7),
                                'expiry_reason' => 'unverified_timeout',
                                'updated_at' => $now,
                            ]);
                        if ($updated) {
                            AccessRequestAuditEvent::query()->create([
                                'event_type' => 'request_expired',
                                'email_normalized' => $request->email_normalized,
                                'access_request_id' => $request->id,
                                'actor_user_id' => null,
                                'context' => ['expiry_reason' => 'unverified_timeout'],
                                'created_at' => $now,
                            ]);
                        }

                        return $updated;
                    });
                    if ($updated) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    /**
     * @return array{verification: AccessRequestVerification, raw_token: string}
     */
    public function create(string $fullName, string $email, AccessRequest $request): array
    {
        $this->purgeExpired();

        $normalized = $this->policy->normalizeEmail($email);
        $rawToken = $this->generateRawToken();

        $verification = AccessRequestVerification::query()->create([
            'access_request_id' => $request->id,
            'token_encrypted' => Crypt::encryptString($rawToken),
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

    public function resend(AccessRequest $request): array
    {
        if (! $request->isPending() || $request->verification_status !== AccessRequest::VERIFICATION_UNVERIFIED) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'request' => ['Only active unverified requests can receive another verification email.'],
            ]);
        }

        AccessRequestVerification::query()->where('access_request_id', $request->id)
            ->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);

        $result = $this->create($request->full_name, $request->email_normalized, $request);

        return [
            'verification' => $result['verification'],
            'url' => $this->verificationUrl($result['raw_token']),
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
