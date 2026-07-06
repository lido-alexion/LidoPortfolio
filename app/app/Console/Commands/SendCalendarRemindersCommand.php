<?php

namespace App\Console\Commands;

use App\Services\CalendarReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendCalendarRemindersCommand extends Command
{
    protected $signature = 'portfolio:send-calendar-reminders {--date= : YYYY-MM-DD date to treat as today}';

    protected $description = 'Send Telegram reminders for portfolio calendar events';

    public function handle(CalendarReminderService $reminders): int
    {
        $dateOption = $this->option('date');
        $onDate = is_string($dateOption) && $dateOption !== ''
            ? Carbon::parse($dateOption)->startOfDay()
            : null;

        $result = $reminders->sendDueReminders($onDate);

        if (($result['sent'] ?? 0) === 0) {
            $this->info('No calendar reminders sent.');

            return 0;
        }

        $this->info(sprintf(
            'Sent %d calendar reminder(s) across %d profile(s). Skipped %d.',
            $result['sent'],
            $result['profiles_notified'],
            $result['skipped'],
        ));

        return 0;
    }
}
