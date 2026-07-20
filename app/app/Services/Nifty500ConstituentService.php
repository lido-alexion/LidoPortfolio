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
