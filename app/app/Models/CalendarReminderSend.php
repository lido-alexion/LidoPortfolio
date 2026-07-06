<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarReminderSend extends Model
{
    protected $table = 'portfolio_calendar_reminder_sends';

    protected $fillable = [
        'event_id',
        'occurrence_date',
        'days_before',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'occurrence_date' => 'date',
            'days_before' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }
}
