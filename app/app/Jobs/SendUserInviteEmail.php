<?php

namespace App\Jobs;

use App\Mail\UserInvitationMail;
use App\Models\UserInvite;
use App\Services\UserInviteService;
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

class SendUserInviteEmail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $inviteId) {}

    public function uniqueId(): string
    {
        return 'user-invite-email:'.$this->inviteId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(UserInviteService $invites): void
    {
        $invite = UserInvite::query()->findOrFail($this->inviteId);
        if (! $invite->isPending() || $invite->email_delivery_status === 'accepted') return;
        if (! $invite->token_encrypted) {
            $invite->update(['email_delivery_status' => 'failed', 'email_last_error_code' => 'INVITE_TOKEN_UNAVAILABLE']);
            return;
        }

        $invite->increment('email_delivery_attempts');
        $invite->update(['email_delivery_status' => 'processing', 'email_last_error_code' => null]);
        try {
            $rawToken = Crypt::decryptString($invite->token_encrypted);
            Mail::to($invite->email)->send(new UserInvitationMail(
                $invites->composeInviteMessage($invite, $rawToken, $invite->accessRequest?->full_name)
            ));
            $invite->update(['email_delivery_status' => 'accepted', 'email_accepted_at' => now(), 'email_last_error_code' => null]);
        } catch (Throwable $exception) {
            $invite->update([
                'email_delivery_status' => $this->attempts() >= $this->tries ? 'failed' : 'queued',
                'email_last_error_code' => 'MAIL_DELIVERY_FAILED',
            ]);
            Log::warning('user_invite.email_delivery_failed', ['invite_id' => $invite->id]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        UserInvite::query()->whereKey($this->inviteId)->whereNull('accepted_at')->update([
            'email_delivery_status' => 'failed',
            'email_last_error_code' => 'MAIL_DELIVERY_FAILED',
            'updated_at' => now(),
        ]);
        Log::warning('user_invite.email_delivery_exhausted', ['invite_id' => $this->inviteId]);
    }
}
