<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\FundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;

class FundamentalHistoricalIngestService
{
    /** @var list<FundamentalHistoricalSource> */
    protected array $sources;

    public function __construct(
        NseOfficialFundamentalHistoricalSource $nse,
        BseOfficialFundamentalHistoricalSource $bse,
        YahooFundamentalHistoricalSource $yahoo,
    ) {
        $this->sources = [$nse, $bse, $yahoo];
        usort($this->sources, fn (FundamentalHistoricalSource $a, FundamentalHistoricalSource $b) => $a->priority() <=> $b->priority());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(Stock $stock, string $cadence): array
    {
        $merged = [];

        foreach ($this->sources as $source) {
            if (! $source->supports($stock)) {
                continue;
            }
            foreach ($source->fetch($stock, $cadence) as $row) {
                $identity = $this->identityKey($row);
                if ($identity === '') {
                    continue;
                }
                $existing = $merged[$identity] ?? null;
                if ($existing === null || $this->shouldReplace($existing, $row, $source->id())) {
                    $row['provider'] = $source->id();
                    $merged[$identity] = $row;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function identityKey(array $row): string
    {
        return implode('|', [
            (string) ($row['statement_type'] ?? ''),
            (string) ($row['cadence'] ?? ''),
            // Match persistence's legacy fallback; official adapters supply unknown.
            (string) ($row['statement_basis'] ?? 'consolidated'),
            (string) ($row['fact_key'] ?? ''),
            (string) ($row['period_end'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    protected function shouldReplace(array $existing, array $incoming, string $incomingSource): bool
    {
        $rank = ['nse_official' => 1, 'bse_official' => 2, 'yahoo' => 3];
        $existingRank = $rank[(string) ($existing['provider'] ?? 'yahoo')] ?? 99;
        $incomingRank = $rank[$incomingSource] ?? 99;

        return $incomingRank < $existingRank;
    }
}
