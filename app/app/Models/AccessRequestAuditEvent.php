<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRequestAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'stox_access_request_audit_events';

    protected $fillable = [
        'event_type',
        'email_normalized',
        'access_request_id',
        'actor_user_id',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
