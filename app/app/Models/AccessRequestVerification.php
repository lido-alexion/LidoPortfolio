<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequestVerification extends Model
{
    protected $table = 'portfolio_access_request_verifications';

    protected $fillable = [
        'full_name',
        'email_normalized',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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
