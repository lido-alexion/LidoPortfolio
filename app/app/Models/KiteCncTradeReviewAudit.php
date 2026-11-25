<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteCncTradeReviewAudit extends Model
{
    public $timestamps = false;
    protected $table = 'kite_cnc_trade_review_audits';
    protected $guarded = [];
    protected function casts(): array { return ['created_at' => 'datetime']; }
}
