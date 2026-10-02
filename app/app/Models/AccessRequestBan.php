<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRequestBan extends Model
{
    protected $table = 'stox_access_request_bans';

    protected $fillable = [
        'email_normalized',
        'access_request_id',
        'rejected_by_user_id',
        'rejected_at',
        'internal_reason',
        'cleared_by_user_id',
        'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'rejected_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->cleared_at === null;
    }
}
