<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'name', 'definition', 'schema_version', 'is_default'])]
class DashboardLayout extends Model
{
    protected $table = 'portfolio_dashboard_layouts';

    protected function casts(): array
    {
        return ['definition' => 'array', 'is_default' => 'boolean', 'schema_version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
