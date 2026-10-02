<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockClassificationOverrideRevision extends Model
{
    public $timestamps = false;

    protected $table = 'stox_stock_classification_override_revisions';

    protected $fillable = ['override_id', 'stock_id', 'actor_id', 'action', 'before_payload', 'after_payload', 'created_at'];

    protected function casts(): array
    {
        return ['before_payload' => 'array', 'after_payload' => 'array', 'created_at' => 'datetime'];
    }
}
