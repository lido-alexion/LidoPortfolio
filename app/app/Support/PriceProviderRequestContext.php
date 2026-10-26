<?php

namespace App\Support;

use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** In-process correlation for existing private provider logs; never sent as HTTP headers. */
class PriceProviderRequestContext
{
    private static array $stack = [];

    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    public static function run(
        ?Stock $stock,
        string $provider,
        ?string $providerSymbol,
        Carbon $from,
        Carbon $to,
        callable $request,
    ): mixed {
        $symbol = strtoupper(trim((string) $stock?->symbol));
        $providerSymbol = strtoupper(trim($providerSymbol ?? $symbol));
        $exchange = strtoupper(trim((string) $stock?->exchange));
        // Raw lookup paths and arbitrary caller context never become protected evidence.
        if (! $stock?->exists || (int) $stock->id <= 0
            || ! in_array($provider, ['nse', 'bse_bhavcopy', 'yahoo', 'alpha_vantage'], true)
            || ! in_array($exchange, ['NSE', 'BSE'], true)
            || ! preg_match('/\A[A-Z0-9^][A-Z0-9.^&=-]{0,39}\z/', $symbol)
            || ! preg_match('/\A[A-Z0-9^][A-Z0-9.^&=-]{0,39}\z/', $providerSymbol)) {
            return self::uncorrelated($request);
        }

        try {
            $context = [
                'request_id' => (string) Str::uuid(),
                'provider' => $provider,
                'symbol' => $symbol,
                'exchange' => $exchange,
                'provider_symbol' => $providerSymbol,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'from_timezone' => $from->getTimezone()->getName(),
                'to_timezone' => $to->getTimezone()->getName(),
            ];
        } catch (\Throwable) {
            return self::uncorrelated($request);
        }
        self::$stack[] = $context;

        try {
            return $request();
        } finally {
            array_pop(self::$stack);
        }
    }

    private static function uncorrelated(callable $request): mixed
    {
        self::$stack[] = null;
        try {
            return $request();
        } finally {
            array_pop(self::$stack);
        }
    }
}
