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
        $rejectedRows = [];
        foreach ($this->sources as $source) {
            if (! $source->supports($stock)) {
                continue;
            }

            $rowsByIdentity = [];
            foreach ($source->fetch($stock, $cadence) as $row) {
                $identity = $this->identityKey($row);
                if ($identity === '') {
                    continue;
                }
                $row['provider'] = $source->id();
                if (! is_numeric($row['value'] ?? null)) {
                    $rejectedRows[] = $row;
                    continue;
                }
                if (! isset($rowsByIdentity[$identity])) {
                    $rowsByIdentity[$identity] = $row;
                }
            }
            $rows = array_values($rowsByIdentity);

            // Provider fallback is sequential: do not query exchange feeds when
            // Yahoo already returned usable rows. A later source is used only
            // when the earlier source has no usable result for this stock/cadence.
            if ($rows !== []) {
                return $rows;
            }
        }

        // Preserve malformed numeric candidates for the bootstrap quality gate
        // when no primary or fallback provider could supply usable facts.
        return $rejectedRows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function identityKey(array $row): string
    {
        $required = ['statement_type', 'cadence', 'fact_key', 'period_end'];
        foreach ($required as $key) {
            if (! is_scalar($row[$key] ?? null) || trim((string) $row[$key]) === '') {
                return '';
            }
        }

        return implode('|', [
            (string) $row['statement_type'],
            (string) $row['cadence'],
            // Match persistence's legacy fallback; official adapters supply unknown.
            (string) ($row['statement_basis'] ?? 'consolidated'),
            (string) $row['fact_key'],
            (string) $row['period_end'],
        ]);
    }

}
