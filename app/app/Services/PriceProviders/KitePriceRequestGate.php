<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Shared per-Kite-app request pacing and short circuit for market-data reads. */
final class KitePriceRequestGate
{
    public function before(string $kind, int $userId): void
    {
        $circuitKey = 'kite:price:circuit:'.$userId.':'.$kind;
        if (Cache::get($circuitKey, 0) >= 3) {
            throw new RuntimeException('Kite market-data circuit is open.');
        }

        $minimumInterval = $kind === 'quote' ? 1.0 : (1.0 / 3.0);
        $lockKey = 'kite:price:pace:'.$kind;
        Cache::lock($lockKey, 5)->block(3, function () use ($kind, $minimumInterval): void {
            $last = (float) Cache::get('kite:price:last:'.$kind, 0);
            $wait = $minimumInterval - (microtime(true) - $last);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000));
            }
            Cache::put('kite:price:last:'.$kind, microtime(true), 30);
        });
    }

    public function succeeded(string $kind, int $userId): void
    {
        Cache::forget('kite:price:circuit:'.$userId.':'.$kind);
    }

    public function failed(string $kind, int $userId): void
    {
        $key = 'kite:price:circuit:'.$userId.':'.$kind;
        // Anchor the cooldown to the first failure. Repeated calls while the
        // circuit is open must not keep extending the outage indefinitely.
        if (Cache::add($key, 1, 30)) {
            return;
        }

        if ((int) Cache::get($key, 0) >= 3) {
            return;
        }

        Cache::increment($key);
    }
}
