<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'items'])]
class ExportBasket extends Model
{
    protected $table = 'portfolio_export_baskets';

    protected $attributes = [
        'items' => '[]',
    ];

    protected function casts(): array { return ['items' => 'array']; }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
