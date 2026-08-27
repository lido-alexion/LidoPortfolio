<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortfolioProfile extends Model
{
    use SoftDeletes;

    public const EXECUTION_MODE_MANUAL = 'manual';

    public const EXECUTION_MODE_SEMI_AUTOMATIC = 'semi_automatic';

    public const EXECUTION_MODE_AUTOMATIC = 'automatic';

    public const EXECUTION_MODES = [
        self::EXECUTION_MODE_MANUAL,
        self::EXECUTION_MODE_SEMI_AUTOMATIC,
        self::EXECUTION_MODE_AUTOMATIC,
    ];

    protected $table = 'portfolio_profiles';

    protected $fillable = [
        'user_id',
        'name',
        'is_default',
    ];

    protected $attributes = [
        'execution_mode' => self::EXECUTION_MODE_MANUAL,
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_default' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function executionMode(): string
    {
        $mode = (string) ($this->execution_mode ?: self::EXECUTION_MODE_MANUAL);

        return in_array($mode, self::EXECUTION_MODES, true)
            ? $mode
            : self::EXECUTION_MODE_MANUAL;
    }

    public function isManualExecution(): bool
    {
        return $this->executionMode() === self::EXECUTION_MODE_MANUAL;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return static::query()
            ->where('user_id', $user->id)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'profile_id');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class, 'profile_id');
    }

    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class, 'profile_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'profile_id');
    }

    public function alertPolicies(): HasMany
    {
        return $this->hasMany(AlertPolicy::class, 'profile_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(ProfileSetting::class, 'profile_id');
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class, 'profile_id');
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(WatchlistItem::class, 'profile_id');
    }

    public function knowledgeNotes(): HasMany
    {
        return $this->hasMany(KnowledgeNote::class, 'profile_id');
    }

    public function knowledgeTags(): HasMany
    {
        return $this->hasMany(KnowledgeTag::class, 'profile_id');
    }

    public function corporateActions(): HasMany
    {
        return $this->hasMany(CorporateAction::class, 'profile_id');
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'profile_id');
    }

    public function screeners(): HasMany
    {
        return $this->hasMany(Screener::class, 'profile_id');
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(TradingStrategy::class, 'profile_id');
    }

    public function capitalRequests(): HasMany
    {
        return $this->hasMany(CapitalRequest::class, 'profile_id');
    }

    public function capitalLoans(): HasMany
    {
        return $this->hasMany(CapitalLoan::class, 'profile_id');
    }
}
