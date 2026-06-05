<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_system_logs';

    protected $fillable = [
        'category',
        'level',
        'message',
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
}
