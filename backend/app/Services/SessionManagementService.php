<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionManagementService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, string $currentSessionId): array
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($row) use ($currentSessionId) {
                $loggedInAt = $this->extractLoggedInAt($row->payload);

                return [
                    'id' => $row->id,
                    'ip_address' => $row->ip_address,
                    'device' => $this->describeUserAgent($row->user_agent),
                    'user_agent' => $row->user_agent,
                    'is_current' => $row->id === $currentSessionId,
                    'last_activity' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                    'login_time' => $loggedInAt?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    public function destroyOtherSessions(int $userId, string $currentSessionId): int
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    public function destroySession(int $userId, string $sessionId, string $currentSessionId): bool
    {
        if ($sessionId === $currentSessionId) {
            return false;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->where('id', $sessionId)
            ->delete() > 0;
    }

    protected function describeUserAgent(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $ua = strtolower($userAgent);
        $device = str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')
            ? 'Mobile'
            : 'Desktop';

        $browser = match (true) {
            str_contains($ua, 'edg') => 'Edge',
            str_contains($ua, 'chrome') => 'Chrome',
            str_contains($ua, 'firefox') => 'Firefox',
            str_contains($ua, 'safari') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'iOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'OS',
        };

        return "{$device} · {$browser} · {$os}";
    }

    protected function extractLoggedInAt(string $payload): ?Carbon
    {
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && isset($decoded['logged_in_at'])) {
            return Carbon::createFromTimestamp((int) $decoded['logged_in_at']);
        }

        try {
            $legacy = @unserialize(base64_decode($payload));
            if (is_array($legacy) && isset($legacy['logged_in_at'])) {
                return Carbon::createFromTimestamp((int) $legacy['logged_in_at']);
            }
        } catch (\Throwable) {
            // ignore legacy decode errors
        }

        return null;
    }
}
