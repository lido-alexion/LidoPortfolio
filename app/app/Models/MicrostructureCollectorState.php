<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrostructureCollectorState extends Model
{
    protected $table = 'stox_microstructure_collector_state';

    protected $fillable = [
        'manual_hold',
        'manual_hold_by_user_id',
        'manual_hold_at',
        'last_command',
        'last_command_at',
        'last_command_by_user_id',
        'universe_refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'manual_hold' => 'boolean',
            'manual_hold_at' => 'datetime',
            'last_command_at' => 'datetime',
            'universe_refreshed_at' => 'datetime',
        ];
    }

    public function manualHoldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_hold_by_user_id');
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }
}
