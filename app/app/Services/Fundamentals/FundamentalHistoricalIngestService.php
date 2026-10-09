<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\FundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;
use App\Services\Nifty500ConstituentService;
use App\Services\Fundamentals\Historical\ExchangeRequestDeferred;
use Throwable;

class FundamentalHistoricalIngestService
{
    /** @var list<FundamentalHistoricalSource> */
    protected array $sources;

    private ?string $lastProviderChecked = null;

    public function __construct(
        NseOfficialFundamentalHistoricalSource $nse,
        BseOfficialFundamentalHistoricalSource $bse,
        YahooFundamentalHistoricalSource $yahoo,
        private readonly ?Nifty500ConstituentService $nifty500 = null,
    ) {
        $this->sources = [$nse, $bse, $yahoo];
        usort($this->sources, fn (FundamentalHistoricalSource $a, FundamentalHistoricalSource $b) => $a->priority() <=> $b->priority());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(Stock $stock, string $cadence, bool $manualStockRequest = false): array
    {
        $this->lastProviderChecked = null;
        $rejectedRows = [];
        $lastSourceError = null;
        foreach ($this->sources as $source) {
            if (! $source->supports($stock)) {
                continue;
            }

            // Automatic exchange fallback is deliberately limited to NSE NIFTY 500.
            // A user-initiated request may target one other stock, but it still passes
            // through the same feed-enabled and access-authorization gates.
            if (! $manualStockRequest && $source->id() !== 'yahoo') {
                if ($source->id() !== 'nse_official'
                    || ! in_array(strtoupper((string) $stock->exchange), ['NSE', 'NSE+'], true)
                    || ! in_array(strtoupper((string) $stock->symbol), ($this->nifty500 ?? app(Nifty500ConstituentService::class))->cachedSymbols(), true)) {
                    continue;
                }
            }

            $this->lastProviderChecked = $source->id();
            $rowsByIdentity = [];
            try {
                $sourceRows = $source->fetch($stock, $cadence);
            } catch (ExchangeRequestDeferred $deferred) {
                throw $deferred;
            } catch (Throwable $error) {
                // Continue only through the already-authorized source order. If
                // every source fails, surface an error so the job cannot record success.
                $lastSourceError = $error;
                continue;
            }

            foreach ($sourceRows as $row) {
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

        if ($lastSourceError !== null) {
            throw $lastSourceError;
        }

        // Preserve malformed numeric candidates when providers returned rows,
        // but none passed the quality gate.
        return $rejectedRows;
    }

    public function lastProviderChecked(): ?string
    {
        return $this->lastProviderChecked;
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
