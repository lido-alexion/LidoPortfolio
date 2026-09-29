<?php

namespace App\Services\ML;

use App\Models\V8\IntradayBackfillCheckpoint;
use App\Models\V8\IntradayBackfillControl;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

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
        if (! in_array($status, ['pending', 'running', 'complete', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid checkpoint status.');
        }
        if ($windowStart !== null && $windowEnd !== null && Carbon::parse($windowEnd)->lt(Carbon::parse($windowStart))) {
            throw ValidationException::withMessages(['window_end' => ['window_end must not precede window_start.']]);
        }

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
        } else {
            $checkpoint->completed_at = null;
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

    public function find(string $symbol, string $exchange, ?string $windowStart, ?string $windowEnd): ?IntradayBackfillCheckpoint
    {
        return IntradayBackfillCheckpoint::query()
            ->where('symbol', strtoupper(trim($symbol)))
            ->where('exchange', strtoupper(trim($exchange ?: 'NSE')))
            ->whereDate('window_start', $windowStart)
            ->whereDate('window_end', $windowEnd)
            ->first();
    }

    public function control(): IntradayBackfillControl
    {
        return IntradayBackfillControl::query()->firstOrCreate(
            ['control_key' => 'global'],
            ['paused' => false]
        );
    }

    public function setPaused(bool $paused, ?int $userId = null): IntradayBackfillControl
    {
        $control = $this->control();
        $control->forceFill(['paused' => $paused, 'updated_by' => $userId])->save();

        return $control->fresh();
    }
}
