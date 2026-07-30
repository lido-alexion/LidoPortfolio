<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenerVersion extends Model
{
    protected $table = 'portfolio_screener_versions';

    protected $fillable = [
        'screener_id',
        'version',
        'definition_json',
        'metadata_json',
        'definition_hash',
        'change_notes',
    ];

    protected function casts(): array
    {
        return [
            'screener_id' => 'integer',
            'version' => 'integer',
            'definition_json' => 'array',
            'metadata_json' => 'array',
        ];
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(Screener::class, 'screener_id');
    }
}
