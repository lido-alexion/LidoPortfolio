<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportNotificationOutbox extends Model
{
    protected $table = 'portfolio_export_notification_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'last_dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ExportArtifact::class, 'artifact_id');
    }
}
