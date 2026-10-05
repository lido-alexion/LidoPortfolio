<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequestVerification extends Model
{
    protected $table = 'stox_access_request_verifications';

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected $fillable = [
        'access_request_id',
        'token_encrypted',
        'email_delivery_status',
        'email_delivery_attempts',
        'email_queued_at',
        'email_accepted_at',
        'email_last_error_code',
        'full_name',
        'email_normalized',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    public function accessRequest(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AccessRequest::class, 'access_request_id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'email_queued_at' => 'datetime',
            'email_accepted_at' => 'datetime',
        ];
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
