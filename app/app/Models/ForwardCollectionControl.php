<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForwardCollectionControl extends Model
{
    protected $table = 'stox_forward_collection_control';
    protected $guarded = [];
    protected function casts(): array { return ['paused' => 'boolean', 'changed_at' => 'datetime']; }
}
