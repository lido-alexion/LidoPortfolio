<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTransaction extends Model
{
    protected $table = 'portfolio_tos_order_transactions';

    protected $fillable = [
        'order_id',
        'transaction_id',
        'execution_price',
        'quantity',
        'charges',
        'executed_at',
        'reusable_artifact_version_id',
        'artifact_binding_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'execution_price' => 'decimal:4',
            'quantity' => 'decimal:4',
            'charges' => 'decimal:4',
            'executed_at' => 'datetime',
            'reusable_artifact_version_id' => 'integer',
            'artifact_binding_revision_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TradingOrder::class, 'order_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function reusableArtifactVersion(): BelongsTo
    {
        return $this->belongsTo(ReusableArtifactVersion::class, 'reusable_artifact_version_id');
    }

    public function artifactBindingRevision(): BelongsTo
    {
        return $this->belongsTo(ArtifactBindingRevision::class, 'artifact_binding_revision_id');
    }
}
