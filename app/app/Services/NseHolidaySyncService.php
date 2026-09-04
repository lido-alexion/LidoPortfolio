<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Support\TradingCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NseHolidaySyncService
{
    public const ENDPOINT = 'https://www.nseindia.com/api/holiday-master?type=trading';

    /** @return array{received:int,created:int,updated:int,overridden:int,immutable:int,deactivated:int} */
    public function sync(): array
    {
        $response = Http::timeout(20)->retry(2, 500)->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (compatible; StoX holiday sync)',
        ])->get(self::ENDPOINT);
        if (! $response->successful() || ! is_array($response->json('CM'))) {
            throw new RuntimeException('Official NSE capital-market holiday feed is unavailable.');
        }

        $rows = $response->json('CM');
        $stats = ['received' => count($rows), 'created' => 0, 'updated' => 0, 'overridden' => 0, 'immutable' => 0, 'deactivated' => 0];
        $today = now()->timezone('Asia/Kolkata')->toDateString();
        $seen = [];
        foreach ($rows as $row) {
            $date = Carbon::createFromFormat('d-M-Y', (string) ($row['tradingDate'] ?? ''));
            $dateKey = $date->format('Y-m-d');
            $externalKey = 'CM:'.$dateKey;
            $seen[] = $externalKey;
            $event = CalendarEvent::query()->where('source', 'nse')->where('external_key', $externalKey)->first();
            if ($event?->sync_override) {
                $stats['overridden']++;
                continue;
            }
            if ($event && $event->anchor_date?->toDateString() <= $today) {
                $stats['immutable']++;
                continue;
            }
            $payload = [
                'profile_id' => null,
                'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
                'source' => 'nse',
                'external_key' => $externalKey,
                'title' => trim((string) ($row['description'] ?? 'NSE trading holiday')),
                'description' => 'Official NSE capital-market trading holiday.',
                'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
                'anchor_date' => $dateKey,
                'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
                'recurrence_config' => [],
                'reminder_enabled' => false,
                'reminder_days_before' => [],
                'is_active' => true,
                'last_synced_at' => now(),
            ];
            if ($event) {
                $event->forceFill($payload)->save();
                $stats['updated']++;
            } else {
                CalendarEvent::query()->create($payload);
                $stats['created']++;
            }
        }

        CalendarEvent::query()->where('source', 'nse')->where('sync_override', false)
            ->whereDate('anchor_date', '>', $today)
            ->whereNotIn('external_key', $seen)->where('is_active', true)
            ->each(function (CalendarEvent $event) use (&$stats): void {
                $event->forceFill(['is_active' => false, 'last_synced_at' => now()])->save();
                $stats['deactivated']++;
            });
        TradingCalendar::clearHolidayCache();
        return $stats;
    }
}
