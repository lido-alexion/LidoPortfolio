<?php

namespace App\Services\Fundamentals\Historical;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Serializes official-feed requests and pauses after rate-limit/block responses. */
class ExchangeRequestGate
{
    private const LOCK_KEY = 'stox:fundamentals:exchange-request-lock';
    private const LAST_REQUEST_KEY = 'stox:fundamentals:exchange-last-request-at';
    private const BLOCKED_UNTIL_KEY = 'stox:fundamentals:exchange-blocked-until';

    public function request(callable $send): mixed
    {
        $lock = Cache::lock(self::LOCK_KEY, 120);
        if (! $lock->get()) {
            throw new ExchangeRequestDeferred(15, 'another request is in progress');
        }

        try {
            $now = now()->timestamp;
            $blockedUntil = (int) Cache::get(self::BLOCKED_UNTIL_KEY, 0);
            if ($blockedUntil > $now) {
                throw new ExchangeRequestDeferred($blockedUntil - $now, 'the source is in cooldown');
            }

            $cap = max(1, min(200, (int) config('fundamentals_bootstrap.exchange_daily_request_cap', 200)));
            $dailyKey = 'stox:fundamentals:exchange-request-count:'.now()->toDateString();
            if ((int) Cache::get($dailyKey, 0) >= $cap) {
                throw new ExchangeRequestDeferred(max(60, now()->endOfDay()->diffInSeconds(now())), 'the daily request cap was reached');
            }

            $minimumGapMs = max(5000, (int) config('fundamentals_bootstrap.exchange_min_gap_ms', 5000));
            $last = (int) Cache::get(self::LAST_REQUEST_KEY, 0);
            $nowMs = (int) floor(microtime(true) * 1000);
            $waitMs = max(0, $minimumGapMs - ($nowMs - $last));
            if ($waitMs > 0) {
                usleep($waitMs * 1000);
            }

            Cache::add($dailyKey, 0, now()->endOfDay()->diffInSeconds(now()));
            Cache::increment($dailyKey);
            try {
                $response = $send();
            } catch (Throwable) {
                Cache::put(self::LAST_REQUEST_KEY, (int) floor(microtime(true) * 1000), 86400);
                throw new ExchangeRequestDeferred(300, 'the source request failed');
            }
            Cache::put(self::LAST_REQUEST_KEY, (int) floor(microtime(true) * 1000), 86400);

            $status = method_exists($response, 'status') ? (int) $response->status() : 0;
            if ($status >= 500) {
                throw new ExchangeRequestDeferred(300, 'the source returned a server error');
            }
            if (in_array($status, [403, 429], true)) {
                $retryAfter = method_exists($response, 'header') ? $response->header('Retry-After') : null;
                $cooldown = (int) config('fundamentals_bootstrap.exchange_block_cooldown_seconds', 21600);
                if (is_numeric($retryAfter)) {
                    $cooldown = max($cooldown, min(86400, (int) $retryAfter));
                }
                Cache::put(self::BLOCKED_UNTIL_KEY, now()->addSeconds($cooldown)->timestamp, $cooldown);
                Log::warning('fundamentals.exchange_source_paused', ['http_status' => $status, 'cooldown_seconds' => $cooldown]);
                throw new ExchangeRequestDeferred($cooldown, 'the source returned HTTP '.$status);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
