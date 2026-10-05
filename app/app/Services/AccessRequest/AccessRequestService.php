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

            if ($check['reason'] === 'existing_user') {
                $this->audit->record('verification_blocked', $normalized, null, null, ['reason' => 'existing_user']);

                return ['message' => $this->genericSubmitMessage()];
            }

            $request = DB::transaction(function () use ($fullName, $normalized) {
                $request = AccessRequest::query()->create([
                    'full_name' => trim($fullName),
                    'email_normalized' => $normalized,
                    'status' => AccessRequest::STATUS_PENDING,
                    'verification_status' => AccessRequest::VERIFICATION_UNVERIFIED,
                    'verified_at' => null,
                ]);
                $result = $this->verifications->create($fullName, $normalized, $request);
                $verificationId = $result['verification']->id;
                DB::afterCommit(fn () => $this->notifications->queueVerificationEmail($normalized, trim($fullName), $request->id, $verificationId));
                $this->audit->record('request_created_unverified', $normalized, $request->id, null, [
                    'verification_id' => $result['verification']->id,
                ]);

                return $request;
            });

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

            $request = $locked?->accessRequest()->lockForUpdate()->first();
            if ($locked === null || $request === null || $locked->isUsed() || $locked->isExpired()
                || ! $request->isPending() || $request->verification_status !== AccessRequest::VERIFICATION_UNVERIFIED) {
                throw ValidationException::withMessages([
                    'token' => ['This verification link is invalid or has expired.'],
                ]);
            }

            $request->verification_status = AccessRequest::VERIFICATION_VERIFIED;
            $request->verified_at = now();
            $request->save();
            $this->verifications->markUsed($locked);
            $this->audit->record('verification_completed', $locked->email_normalized, $request->id, null, [
                'verification_status' => 'verified',
            ]);

            return [
                'message' => 'Your email is verified. An administrator will review your request and contact you by email.',
                'status' => 'pending',
            ];
            });
        });
    }

    public function genericSubmitMessage(): string
    {
        return 'If this email is eligible, an administrator can review the access request. If you already use StoX, please use the login page.';
    }

    protected function emailLockKey(string $normalized): string
    {
        return 'access-request:email:'.hash('sha256', $normalized);
    }
}
