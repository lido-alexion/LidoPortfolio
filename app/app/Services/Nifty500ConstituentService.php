<?php

namespace App\Services;

class Nifty500ConstituentService
{
    public const CACHE_KEY = 'nifty500_constituents_json';

    public const CACHE_AT_KEY = 'nifty500_constituents_cached_at';

    public function __construct(
        protected IndexConstituentService $constituents,
    ) {}

    /**
     * @return list<string> Uppercase NSE symbols (no suffix)
     */
    public function symbols(bool $forceRefresh = false): array
    {
        $symbols = $this->constituents->symbols('NIFTY500', $forceRefresh);
        if ($symbols !== []) {
            return $symbols;
        }

        return $this->legacyCachedSymbols();
    }

    /**
     * Read only an already-cached, fresh membership list. This method never makes
     * a network request and is used to decide automatic exchange fallback scope.
     *
     * @return list<string>
     */
    public function cachedSymbols(): array
    {
        $symbols = $this->constituents->cachedSymbols('NIFTY500');
        if ($symbols !== []) {
            return $symbols;
        }

        $cachedAt = \App\Models\Setting::getValue(self::CACHE_AT_KEY);
        if (! is_string($cachedAt) || $cachedAt === '') {
            return [];
        }
        try {
            if (\Carbon\Carbon::parse($cachedAt)->lt(now()->subDays(max(1, (int) config('portfolio.universe_price_sync.nifty500_cache_days', 7))))) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return $this->legacyCachedSymbols();
    }

    /**
     * Legacy cache keys used before IndexConstituentService.
     *
     * @return list<string>
     */
    protected function legacyCachedSymbols(): array
    {
        $raw = \App\Models\Setting::getValue(self::CACHE_KEY);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($s) => is_string($s) ? strtoupper(trim($s)) : null,
            $decoded,
        ))));
    }
}
