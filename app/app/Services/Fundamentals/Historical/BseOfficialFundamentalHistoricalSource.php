<?php

namespace App\Services\Fundamentals\Historical;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FEAT-054 — BSE official facts via operator-configured JSON feed (BSE-only canonical securities).
 */
class BseOfficialFundamentalHistoricalSource implements FundamentalHistoricalSource
{
    public function id(): string
    {
        return 'bse_official';
    }

    public function priority(): int
    {
        return 20;
    }

    public function supports(Stock $stock): bool
    {
        if (! config('fundamentals_bootstrap.bse_official_enabled', false)) {
            return false;
        }

        return strtoupper((string) $stock->exchange) === 'BSE';
    }

    public function fetch(Stock $stock, string $cadence): array
    {
        $url = trim((string) config('fundamentals_bootstrap.bse_official_feed_url', ''));
        if ($url === '') {
            return [];
        }

        $timeout = (float) config('fundamentals_bootstrap.bse_official_timeout_seconds', 30);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($url, [
                    'symbol' => strtoupper((string) $stock->symbol),
                    'cadence' => $cadence,
                    'exchange' => 'BSE',
                ]);
        } catch (\Throwable $e) {
            Log::warning('fundamentals.bse_official_fetch_failed', [
                'stock_id' => $stock->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();
        $rows = is_array($payload['facts'] ?? null) ? $payload['facts'] : (is_array($payload) ? $payload : []);

        return $this->normalizeRows($rows, $cadence);
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    protected function normalizeRows(array $rows, string $cadence): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $factKey = (string) ($row['fact_key'] ?? '');
            $periodEnd = (string) ($row['period_end'] ?? '');
            if ($factKey === '' || $periodEnd === '') {
                continue;
            }
            $out[] = [
                'statement_type' => (string) ($row['statement_type'] ?? 'income_statement'),
                'cadence' => (string) ($row['cadence'] ?? $cadence),
                'fact_key' => $factKey,
                'period_end' => $periodEnd,
                'value' => $row['value'] ?? null,
                'availability_date' => $row['availability_date'] ?? null,
                'currency' => $row['currency'] ?? 'INR',
            ];
        }

        return $out;
    }
}
