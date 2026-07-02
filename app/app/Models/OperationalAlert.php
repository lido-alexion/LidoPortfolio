<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalAlert extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'alert_key';

    protected $table = 'portfolio_operational_alerts';

    public $timestamps = false;

    protected $fillable = [
        'alert_key',
        'severity',
        'title',
        'message',
        'context',
        'first_triggered_at',
        'last_triggered_at',
        'resolved_at',
        'last_telegram_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_triggered_at' => 'datetime',
            'last_triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_telegram_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->resolved_at === null;
    }
}
