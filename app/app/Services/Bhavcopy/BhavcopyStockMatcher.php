<?php

namespace App\Services\Bhavcopy;

use App\Models\Stock;
use App\Services\ProviderResolverService;
use Illuminate\Support\Collection;

/** Resolve only canonical eligible records; conflicting identities fail closed. */
class BhavcopyStockMatcher
{
    public function __construct(
        protected ProviderResolverService $symbols,
        protected NsePriceIdentityEvidence $identities,
    ) {}

    public function evidenceHash(): string
    {
        return $this->identities->hash();
    }

    public function match(Collection $stocks, array $rows, string $exchange): array
    {
        $byPrimary = $byIsin = $conflicts = [];
        foreach ($stocks as $stock) {
            if (strtoupper((string) $stock->exchange) !== $exchange) {
                continue;
            }
            [$symbol, $series] = $this->nseIdentity($stock);
            [$baseSymbol, $suffixSeries] = $this->symbols->parseNseTradeSymbol((string) $stock->symbol);
            if ($exchange === 'NSE' && $baseSymbol !== strtoupper(trim((string) $stock->symbol))
                && trim((string) $stock->series) !== '' && $series !== $suffixSeries) {
                $conflicts[(int) $stock->id] = 'conflicting_master_series';
                continue;
            }
            $primary = $exchange === 'NSE' ? $symbol.'|'.$series : trim((string) $stock->bse_scrip_code);
            if ($primary !== '') {
                $byPrimary[$primary][] = $stock;
            }
            $isin = strtoupper(trim((string) $stock->isin));
            if ($isin !== '') {
                $byIsin[$isin.($exchange === 'NSE' ? '|'.$series : '')][] = $stock;
            }
        }
        $matches = $historical = [];
        $unmatched = 0;
        foreach ($rows as $row) {
            $key = $exchange === 'NSE' ? $row['symbol'].'|'.$row['series'] : $row['scrip_code'];
            $primary = $byPrimary[$key] ?? [];
            $isin = $row['isin'] !== '' ? ($byIsin[$row['isin'].($exchange === 'NSE' ? '|'.$row['series'] : '')] ?? []) : [];
            $canonicalIsin = $row['isin'];
            $isHistorical = false;
            // Only the same symbol/EQ series and one unique current target can
            // acknowledge a dated old ISIN. Never steal another current identity.
            if ($exchange === 'NSE' && $row['series'] === 'EQ' && count($primary) === 1 && $isin === []
                && $this->nseIdentity($primary[0])[0] === $row['symbol']) {
                $evidenced = $this->identities->canonicalIsin([
                    'symbol' => $row['symbol'], 'isin' => $row['isin'],
                ], $row['price_date'] ?? '');
                if ($evidenced !== null && $evidenced === strtoupper(trim((string) $primary[0]->isin))) {
                    $canonicalIsin = $evidenced;
                    $isin = $byIsin[$evidenced.'|'.$row['series']] ?? [];
                    $isHistorical = true;
                }
            }
            $candidates = $primary !== [] ? $primary : $isin;
            $all = collect(array_merge($primary, $isin))->keyBy('id');
            if (count($candidates) !== 1 || count($isin) > 1 || $all->count() > 1) {
                foreach ($all as $candidate) {
                    $conflicts[(int) $candidate->id] = 'ambiguous_identity';
                }
                $unmatched++;
                continue;
            }
            $stock = $candidates[0];
            $id = (int) $stock->id;
            $knownIsin = strtoupper(trim((string) $stock->isin));
            if (($knownIsin !== '' && $knownIsin !== $canonicalIsin) || isset($matches[$id])) {
                $conflicts[$id] = 'conflicting_identity_or_rows';
                unset($matches[$id]);
                continue;
            }
            if (! isset($conflicts[$id])) {
                $matches[$id] = $row;
                if ($isHistorical) {
                    $historical[$id] = true;
                }
            }
        }
        foreach ($conflicts as $id => $_) {
            unset($matches[$id]);
        }

        return [
            'rows_by_stock_id' => $matches, 'conflicts' => $conflicts,
            'unmatched_rows' => $unmatched, 'historical_stock_ids' => array_keys(array_intersect_key($historical, $matches)),
        ];
    }

    private function nseIdentity(Stock $stock): array
    {
        [$symbol, $parsedSeries] = $this->symbols->parseNseTradeSymbol((string) $stock->symbol);
        $series = strtoupper(trim((string) $stock->series));

        return [$symbol, $series !== '' ? $series : $parsedSeries];
    }
}
