<?php

namespace App\Services\ML;

use App\Models\V8\IntradayBackfillCheckpoint;
use Carbon\Carbon;

class IntradayBackfillCheckpointService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(array $payload): IntradayBackfillCheckpoint
    {
        $symbol = strtoupper(trim((string) ($payload['symbol'] ?? '')));
        $exchange = strtoupper(trim((string) ($payload['exchange'] ?? 'NSE')));
        if ($symbol === '') {
            throw new \InvalidArgumentException('symbol is required.');
        }

        $windowStart = isset($payload['window_start']) ? Carbon::parse($payload['window_start'])->toDateString() : null;
        $windowEnd = isset($payload['window_end']) ? Carbon::parse($payload['window_end'])->toDateString() : null;
        $status = (string) ($payload['status'] ?? 'pending');

        $checkpoint = IntradayBackfillCheckpoint::query()->firstOrNew([
            'symbol' => $symbol,
            'exchange' => $exchange,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ]);

        $checkpoint->fill([
            'status' => $status,
            'bars_written' => (int) ($payload['bars_written'] ?? $checkpoint->bars_written ?? 0),
            'last_error' => is_array($payload['last_error'] ?? null) ? $payload['last_error'] : null,
            'last_attempt_at' => isset($payload['last_attempt_at'])
                ? Carbon::parse($payload['last_attempt_at'])
                : now(),
        ]);

        if ($status === 'complete') {
            $checkpoint->completed_at = isset($payload['completed_at'])
                ? Carbon::parse($payload['completed_at'])
                : now();
        }

        $checkpoint->save();

        return $checkpoint->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return IntradayBackfillCheckpoint::query()
            ->orderByDesc('last_attempt_at')
            ->limit($limit)
            ->get()
            ->map(fn (IntradayBackfillCheckpoint $row) => $row->toArray())
            ->all();
    }
}
