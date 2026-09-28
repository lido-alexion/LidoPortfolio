<?php

namespace App\Services\AccessRequest;

use App\Mail\AccessRequestIgnoredMail;
use App\Mail\AccessRequestRejectedMail;
use App\Mail\AccessRequestVerificationMail;
use App\Models\AccessRequest;
use App\Models\User;
use App\Models\UserInvite;
use App\Services\UserInviteService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccessRequestNotificationService
{
    public function __construct(
        protected UserInviteService $invites,
        protected AccessRequestAuditLogger $audit,
    ) {}

    public function sendVerificationEmail(string $email, string $fullName, string $verificationUrl): void
    {
        try {
            Mail::to($email)->send(new AccessRequestVerificationMail($fullName, $verificationUrl));
        } catch (\Throwable $e) {
            Log::warning('access_request.verification_email_failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            $this->audit->record('verification_email_failed', $email, null, null, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyAdminsPending(AccessRequest $request): void
    {
        $admins = User::query()->where('is_admin', true)->get(['id', 'email']);
        foreach ($admins as $admin) {
            try {
                Mail::raw(
                    "A new verified account access request is pending review for {$request->full_name} ({$request->email_normalized}).",
                    function ($message) use ($admin) {
                        $message->to($admin->email)
                            ->subject('StoX: new account access request');
                    }
                );
            } catch (\Throwable $e) {
                Log::warning('access_request.admin_notify_failed', [
                    'admin_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function sendInviteEmail(UserInvite $invite, string $rawToken): void
    {
        try {
            $this->invites->sendInvitationEmail($invite, $rawToken);
        } catch (\Throwable $e) {
            Log::warning('access_request.invite_email_failed', [
                'invite_id' => $invite->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendIgnoredOutcome(AccessRequest $request): void
    {
        try {
            Mail::to($request->email_normalized)->send(new AccessRequestIgnoredMail($request->full_name));
        } catch (\Throwable $e) {
            Log::warning('access_request.ignored_email_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendRejectedOutcome(AccessRequest $request): void
    {
        try {
            Mail::to($request->email_normalized)->send(new AccessRequestRejectedMail($request->full_name));
        } catch (\Throwable $e) {
            Log::warning('access_request.rejected_email_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
