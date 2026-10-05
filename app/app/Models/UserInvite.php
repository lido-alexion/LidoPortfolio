<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserInvite extends Model
{
    protected $table = 'portfolio_user_invites';

    /**
     * `token` stores a SHA-256 hash of the invitation bearer secret (never the raw token).
     */
    protected $fillable = [
        'email',
        'token',
        'token_encrypted',
        'email_delivery_status',
        'email_delivery_attempts',
        'email_queued_at',
        'email_accepted_at',
        'email_last_error_code',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'user_id',
    ];

    protected $hidden = [
        'token',
        'token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'email_queued_at' => 'datetime',
            'email_accepted_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function accessRequest(): HasOne
    {
        return $this->hasOne(AccessRequest::class, 'user_invite_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
