<?php

namespace App\Services\AccessRequest;

use App\Models\AccessRequest;
use App\Models\AccessRequestVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AccessRequestService
{
    public function __construct(
        protected AccessRequestPolicyService $policy,
        protected AccessRequestVerificationService $verifications,
        protected AccessRequestNotificationService $notifications,
        protected AccessRequestAuditLogger $audit,
        protected HumanVerificationService $captcha,
    ) {}

    /**
     * @return array{message: string}
     */
    public function submitForVerification(string $fullName, string $email, ?string $captchaToken, ?string $remoteIp): array
    {
        if (! $this->captcha->verify($captchaToken, $remoteIp)) {
            throw ValidationException::withMessages([
                'captcha_token' => ['Human verification failed. Please try again.'],
            ]);
        }

        $normalized = $this->policy->normalizeEmail($email);
        return Cache::lock($this->emailLockKey($normalized), 10)->block(5, function () use ($fullName, $normalized): array {
            $check = $this->policy->canStartVerification($normalized);
            if (! $check['allowed']) {
                $this->audit->record('verification_blocked', $normalized, null, null, [
                    'reason' => $check['reason'],
                ]);

                return ['message' => $this->genericSubmitMessage()];
            }

            $result = $this->verifications->create($fullName, $normalized);
            $url = $this->verifications->verificationUrl($result['raw_token']);
            $this->notifications->sendVerificationEmail($normalized, trim($fullName), $url);
            $this->audit->record('verification_initiated', $normalized, null, null, [
                'verification_id' => $result['verification']->id,
            ]);

            return ['message' => $this->genericSubmitMessage()];
        });
    }

    /**
     * @return array{message: string, status: string}
     */
    public function completeVerification(string $rawToken): array
    {
        $verification = $this->verifications->findValidByRawToken($rawToken);
        if ($verification === null) {
            throw ValidationException::withMessages([
                'token' => ['This verification link is invalid or has expired.'],
            ]);
        }

        return Cache::lock($this->emailLockKey($verification->email_normalized), 10)->block(5, function () use ($verification): array {
            return DB::transaction(function () use ($verification) {
            $locked = AccessRequestVerification::query()
                ->whereKey($verification->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isUsed() || $locked->isExpired()) {
                throw ValidationException::withMessages([
                    'token' => ['This verification link is invalid or has expired.'],
                ]);
            }

            $check = $this->policy->canCreatePendingRequest($locked->email_normalized);
            if (! $check['allowed']) {
                $this->verifications->markUsed($locked);
                $this->audit->record('pending_blocked', $locked->email_normalized, null, null, [
                    'reason' => $check['reason'],
                ]);

                return [
                    'message' => 'Thank you. If your request can proceed, an administrator will follow up by email.',
                    'status' => 'blocked',
                ];
            }

            $request = AccessRequest::query()->create([
                'full_name' => $locked->full_name,
                'email_normalized' => $locked->email_normalized,
                'status' => AccessRequest::STATUS_PENDING,
                'verified_at' => now(),
            ]);

            $this->verifications->markUsed($locked);
            $this->audit->record('pending_created', $locked->email_normalized, $request->id);
            $this->notifications->notifyAdminsPending($request);

            return [
                'message' => 'Your email is verified. An administrator will review your request and contact you by email.',
                'status' => 'pending',
            ];
            });
        });
    }

    public function genericSubmitMessage(): string
    {
        return 'If this email is eligible for an access request, a verification email will be sent shortly. If you already have an account, use the login page.';
    }

    protected function emailLockKey(string $normalized): string
    {
        return 'access-request:email:'.hash('sha256', $normalized);
    }
}
