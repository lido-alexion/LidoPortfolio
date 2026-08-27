<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionDecision extends Model
{
    public const OUTCOME_SUBMITTED = 'submitted';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    public const TRIGGER_SEMI = 'semi_automatic';

    public const TRIGGER_AUTOMATIC = 'automatic';

    protected $table = 'portfolio_tos_execution_decisions';

    protected $fillable = [
        'profile_id',
        'recommendation_id',
        'user_id',
        'mode',
        'trigger',
        'outcome',
        'reason',
        'order_id',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(TradingRecommendation::class, 'recommendation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TradingOrder::class, 'order_id');
    }
}
