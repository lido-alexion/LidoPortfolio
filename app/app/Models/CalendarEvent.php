<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarEvent extends Model
{
    public const RECURRENCE_NONE = 'none';

    public const RECURRENCE_DAILY = 'daily';

    public const RECURRENCE_WEEKLY = 'weekly';

    public const RECURRENCE_MONTHLY_DAY = 'monthly_day';

    public const RECURRENCE_MONTHLY_WEEKDAY = 'monthly_weekday';

    public const RECURRENCE_YEARLY_DAY = 'yearly_day';

    public const RECURRENCE_YEARLY_WEEKDAY = 'yearly_weekday';

    /** Global exchange/market holiday visible to every portfolio. */
    public const CATEGORY_TRADE_HOLIDAY = 'trade_holiday';

    public const TRADE_HOLIDAY_DEFAULT_COLOR = '#b45309';

    protected $table = 'portfolio_calendar_events';

    protected $fillable = [
        'profile_id',
        'category',
        'source',
        'external_key',
        'sync_override',
        'last_synced_at',
        'title',
        'description',
        'color',
        'anchor_date',
        'recurrence_type',
        'recurrence_config',
        'recurrence_end_date',
        'reminder_enabled',
        'reminder_days_before',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'anchor_date' => 'date',
            'recurrence_config' => 'array',
            'recurrence_end_date' => 'date',
            'reminder_enabled' => 'boolean',
            'reminder_days_before' => 'array',
            'is_active' => 'boolean',
            'sync_override' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isGlobal(): bool
    {
        return $this->profile_id === null;
    }

    public function isTradeHoliday(): bool
    {
        return $this->category === self::CATEGORY_TRADE_HOLIDAY && $this->isGlobal();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?? $this->getRouteKeyName();
        $profile = \activePortfolio();

        $query = static::query()->where($field, $value);

        // Portfolio events for the active profile, or global trade holidays (any authed user can resolve for read;
        // mutations are gated in the controller).
        if ($profile !== null) {
            $query->where(function (Builder $inner) use ($profile) {
                $inner->where('profile_id', $profile->id)
                    ->orWhere(function (Builder $global) {
                        $global->whereNull('profile_id')
                            ->where('category', self::CATEGORY_TRADE_HOLIDAY);
                    });
            });
        } else {
            $query->whereNull('profile_id')
                ->where('category', self::CATEGORY_TRADE_HOLIDAY);
        }

        return $query->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function reminderSends(): HasMany
    {
        return $this->hasMany(CalendarReminderSend::class, 'event_id');
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeVisibleToProfile(Builder $query, int $profileId): Builder
    {
        return $query->where(function (Builder $inner) use ($profileId) {
            $inner->where('profile_id', $profileId)
                ->orWhere(function (Builder $global) {
                    $global->whereNull('profile_id')
                        ->where('category', self::CATEGORY_TRADE_HOLIDAY);
                });
        });
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeGlobalTradeHolidays(Builder $query): Builder
    {
        return $query->whereNull('profile_id')
            ->where('category', self::CATEGORY_TRADE_HOLIDAY);
    }
}
