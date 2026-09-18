<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable reservation lifecycle evidence used by historical reconstruction. */
class RecommendationReservationEvent extends Model
{
    public const STATE_RESERVED = 'reserved';
    public const STATE_RELEASED = 'released';
    public const STATE_CONVERTED = 'converted';

    protected $table = 'stox_recommendation_reservation_events';
    public $timestamps = false;

    protected $fillable = ['profile_id', 'recommendation_id', 'state', 'amount', 'occurred_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer', 'recommendation_id' => 'integer', 'amount' => 'decimal:4',
            'occurred_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }
}
