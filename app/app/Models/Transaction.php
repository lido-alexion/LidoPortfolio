<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'portfolio_transactions';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_RECOMMENDATION = 'recommendation';

    public const SOURCE_IPO = 'ipo';

    public const SOURCE_BONUS = 'bonus';

    /** Historical / optional purchase tag. V4-SPEC-002: not a corporate-action type. */
    public const SOURCE_RIGHTS = 'rights';

    public const SOURCE_SPLIT = 'split';

    public const SOURCE_DIVIDEND_REINVESTMENT = 'dividend_reinvestment';

    public const SOURCE_OTHER = 'other';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_RECOMMENDATION,
        self::SOURCE_IPO,
        self::SOURCE_BONUS,
        self::SOURCE_RIGHTS,
        self::SOURCE_SPLIT,
        self::SOURCE_DIVIDEND_REINVESTMENT,
        self::SOURCE_OTHER,
    ];

    protected $fillable = [
        'profile_id',
        'stock_id',
        'type',
        'quantity',
        'price',
        'fees',
        'realized_pl',
        'squared_off_fees',
        'transaction_date',
        'notes',
        'corporate_action_id',
        'source',
        'recommendation_id',
        'exit_reason',
        'owner_key',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'stock_id' => 'integer',
            'quantity' => 'decimal:4',
            'price' => 'decimal:4',
            'fees' => 'decimal:4',
            'realized_pl' => 'decimal:4',
            'squared_off_fees' => 'decimal:4',
            'transaction_date' => 'date',
            'corporate_action_id' => 'integer',
            'recommendation_id' => 'integer',
        ];
    }

    /**
     * Only resolve transactions owned by the active portfolio (API update/delete/show).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return $profile->transactions()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function corporateAction(): BelongsTo
    {
        return $this->belongsTo(CorporateAction::class, 'corporate_action_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(TradingRecommendation::class, 'recommendation_id');
    }

    /**
     * Logical strategy owner: recommendation first, then persisted owner_key (V4-SPEC-005).
     * Unmanaged / unlinked rows return null.
     */
    public function owningStrategyId(): ?int
    {
        if ($this->recommendation_id !== null) {
            $this->loadMissing('recommendation.strategyVersion');
            $fromRec = $this->recommendation?->owningStrategyId();
            if ($fromRec !== null) {
                return $fromRec;
            }
        }

        return Holding::strategyIdFromOwnerKey($this->owner_key);
    }
}
