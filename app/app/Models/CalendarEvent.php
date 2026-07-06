<?php

namespace App\Models;

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

    protected $table = 'portfolio_calendar_events';

    protected $fillable = [
        'profile_id',
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
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $profile = \activePortfolio();

        if ($profile === null) {
            return null;
        }

        return static::query()
            ->where('profile_id', $profile->id)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public function reminderSends(): HasMany
    {
        return $this->hasMany(CalendarReminderSend::class, 'event_id');
    }
}
