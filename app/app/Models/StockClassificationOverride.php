<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockClassificationOverride extends Model
{
    protected $table = 'stox_stock_classification_overrides';

    protected $fillable = ['stock_id', 'taxonomy_version', 'sector', 'industry', 'reason', 'created_by', 'updated_by', 'removed_at'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }
}
