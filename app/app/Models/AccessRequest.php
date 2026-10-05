<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CREATED = 'created';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const VERIFICATION_UNVERIFIED = 'unverified';

    public const VERIFICATION_VERIFIED = 'verified';

    protected $table = 'stox_access_requests';

    protected $fillable = [
        'full_name',
        'email_normalized',
        'status',
        'verification_status',
        'verified_at',
        'expires_at',
        'expiry_reason',
        'resolved_by_user_id',
        'resolved_at',
        'admin_reason',
        'user_invite_id',
        'resubmit_allowed_after',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resubmit_allowed_after' => 'datetime',
        ];
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function userInvite(): BelongsTo
    {
        return $this->belongsTo(UserInvite::class, 'user_invite_id');
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(AccessRequestVerification::class, 'access_request_id')->latestOfMany();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
