<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KiteCncTradeImport extends Model
{
    protected $table = 'kite_cnc_trade_imports';
    protected $guarded = [];
    protected $appends = ['review_version'];

    public function getReviewVersionAttribute(): string
    {
        return hash('sha256', implode('|', [(string) $this->id, (string) $this->evidence_key, (string) $this->status,
            (string) $this->broker_account_id, (string) $this->stock_id, (string) $this->symbol, (string) $this->exchange,
            (string) $this->side, (string) $this->quantity, (string) $this->price,
            optional($this->executed_at)->toISOString(), json_encode($this->broker_evidence), optional($this->updated_at)->toISOString()]));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'price' => 'decimal:4', 'executed_at' => 'datetime', 'reviewed_at' => 'datetime', 'broker_evidence' => 'array'];
    }

    public function syncRun() { return $this->belongsTo(KiteCncTradeSyncRun::class, 'sync_run_id'); }
    public function reviewAudits() { return $this->hasMany(KiteCncTradeReviewAudit::class, 'trade_import_id'); }
}
