<?php

namespace App\Jobs;

use App\Mail\AccessRequestVerificationMail;
use App\Models\AccessRequestVerification;
use App\Services\AccessRequest\AccessRequestVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAccessRequestVerificationEmail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $verificationId) {}

    public function uniqueId(): string
    {
        return 'access-request-verification:'.$this->verificationId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AccessRequestVerificationService $verifications): void
    {
        $verification = AccessRequestVerification::query()->with('accessRequest')->findOrFail($this->verificationId);
        $request = $verification->accessRequest;
        if (! $request?->isPending() || $request->verification_status !== 'unverified'
            || $verification->isUsed() || $verification->isExpired()) {
            $verification->update(['email_delivery_status' => 'suppressed']);
            return;
        }
        if (! $verification->token_encrypted) {
            $verification->update(['email_delivery_status' => 'failed', 'email_last_error_code' => 'VERIFY_TOKEN_UNAVAILABLE']);
            return;
        }

        $verification->increment('email_delivery_attempts');
        $verification->update(['email_delivery_status' => 'processing', 'email_last_error_code' => null]);
        try {
            $url = $verifications->verificationUrl(Crypt::decryptString($verification->token_encrypted));
            Mail::to($verification->email_normalized)->send(new AccessRequestVerificationMail($verification->full_name, $url));
            $verification->update(['email_delivery_status' => 'accepted', 'email_accepted_at' => now(), 'email_last_error_code' => null]);
        } catch (Throwable $exception) {
            $verification->update([
                'email_delivery_status' => $this->attempts() >= $this->tries ? 'failed' : 'queued',
                'email_last_error_code' => 'MAIL_DELIVERY_FAILED',
            ]);
            Log::warning('access_request.verification_email_delivery_failed', ['request_id' => $request->id]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        AccessRequestVerification::query()->whereKey($this->verificationId)->update([
            'email_delivery_status' => 'failed',
            'email_last_error_code' => 'MAIL_DELIVERY_FAILED',
            'updated_at' => now(),
        ]);
        Log::warning('access_request.verification_email_delivery_exhausted', ['verification_id' => $this->verificationId]);
    }
}
