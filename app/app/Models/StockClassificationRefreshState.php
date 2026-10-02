<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockClassificationRefreshState extends Model
{
    protected $table = 'stox_stock_classification_refresh_states';

    protected $fillable = [
        'stock_id', 'provider', 'taxonomy_version', 'attempts', 'next_attempt_at',
        'last_attempted_at', 'last_successful_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'next_attempt_at' => 'datetime',
            'last_attempted_at' => 'datetime', 'last_successful_at' => 'datetime',
        ];
    }
}
