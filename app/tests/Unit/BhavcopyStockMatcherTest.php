<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\Bhavcopy\BhavcopyStockMatcher;
use App\Services\ProviderResolverService;
use PHPUnit\Framework\TestCase;

class BhavcopyStockMatcherTest extends TestCase
{
    private function stock(int $id, array $fields = []): Stock
    {
        return (new Stock)->forceFill($fields + [
            'id' => $id, 'symbol' => 'AAA', 'series' => 'EQ', 'exchange' => 'NSE', 'isin' => 'INE001A01010',
        ]);
    }

    private function row(array $fields = []): array
    {
        return $fields + ['symbol' => 'AAA', 'series' => 'EQ', 'isin' => 'INE001A01010', 'scrip_code' => '1001'];
    }

    public function test_matches_nse_series_exactly_and_allows_an_unambiguous_isin_rename(): void
    {
        $matcher = new BhavcopyStockMatcher(new ProviderResolverService);
        $stocks = collect([$this->stock(1, ['series' => 'BE'])]);
        $this->assertSame([], $matcher->match($stocks, [$this->row()], 'NSE')['rows_by_stock_id']);
        $result = $matcher->match($stocks, [$this->row(['series' => 'BE', 'symbol' => 'NEWAAA'])], 'NSE');
        $this->assertSame([1], array_keys($result['rows_by_stock_id']));
    }

    public function test_conflicting_symbol_suffix_and_master_series_are_rejected(): void
    {
        $result = (new BhavcopyStockMatcher(new ProviderResolverService))->match(
            collect([$this->stock(1, ['symbol' => 'AAA-BE', 'series' => 'EQ'])]), [$this->row()], 'NSE',
        );
        $this->assertSame([], $result['rows_by_stock_id']);
        $this->assertSame('conflicting_master_series', $result['conflicts'][1]);
    }

    public function test_an_isin_conflict_never_falls_through_to_a_matching_ticker(): void
    {
        $result = (new BhavcopyStockMatcher(new ProviderResolverService))->match(
            collect([$this->stock(1)]), [$this->row(['isin' => 'INE002A01010'])], 'NSE',
        );
        $this->assertSame([], $result['rows_by_stock_id']);
        $this->assertArrayHasKey(1, $result['conflicts']);
    }

    public function test_bse_uses_scrip_code_and_never_ticker_only_or_cross_exchange_matches(): void
    {
        $stocks = collect([
            $this->stock(1, ['exchange' => 'BSE', 'isin' => null, 'bse_scrip_code' => '500001']),
            $this->stock(2, ['exchange' => 'BSE', 'isin' => null, 'bse_scrip_code' => null]),
            $this->stock(3),
        ]);
        $result = (new BhavcopyStockMatcher(new ProviderResolverService))->match(
            $stocks, [$this->row(['scrip_code' => '500001'])], 'BSE',
        );
        $this->assertSame([1], array_keys($result['rows_by_stock_id']));
    }

    public function test_ambiguous_master_identities_and_multiple_rows_for_one_stock_are_rejected(): void
    {
        $matcher = new BhavcopyStockMatcher(new ProviderResolverService);
        $ambiguous = $matcher->match(collect([$this->stock(1), $this->stock(2)]), [$this->row()], 'NSE');
        $this->assertSame([], $ambiguous['rows_by_stock_id']);
        $this->assertCount(2, $ambiguous['conflicts']);
        $duplicate = $matcher->match(collect([$this->stock(1)]), [$this->row(), $this->row(['scrip_code' => '1002'])], 'NSE');
        $this->assertSame([], $duplicate['rows_by_stock_id']);
        $this->assertArrayHasKey(1, $duplicate['conflicts']);
    }

    private function historicalMatcher(): BhavcopyStockMatcher
    {
        $evidence = new class extends \App\Services\Bhavcopy\NsePriceIdentityEvidence
        {
            public function document(): array
            {
                return require dirname(__DIR__, 2).'/config/universe_nse_price_identities.php';
            }
        };

        return new BhavcopyStockMatcher(new ProviderResolverService, $evidence);
    }

    public function test_evidenced_old_isin_is_dated_symbol_and_series_bound(): void
    {
        $stock = $this->stock(1, ['symbol' => 'AZADIND', 'isin' => 'INE566M01025']);
        $row = $this->row(['symbol' => 'AZADIND', 'isin' => 'INE566M01017', 'price_date' => '2026-10-22']);
        $matcher = $this->historicalMatcher();
        $accepted = $matcher->match(collect([$stock]), [$row], 'NSE');
        $this->assertSame([1], $accepted['historical_stock_ids']);
        $this->assertSame('INE566M01017', $accepted['rows_by_stock_id'][1]['isin']);
        foreach ([
            ['price_date' => '2026-10-14'],
            ['price_date' => '2026-10-23'],
            ['symbol' => 'REUSED'],
            ['series' => 'BE'],
            ['isin' => 'INE999A01010'],
        ] as $fault) {
            $result = $matcher->match(collect([$stock]), [array_replace($row, $fault)], 'NSE');
            $this->assertSame([], $result['historical_stock_ids']);
            $this->assertSame([], $result['rows_by_stock_id']);
        }
    }

    public function test_historical_evidence_never_overrides_ambiguous_or_reused_current_identity(): void
    {
        $row = $this->row(['symbol' => 'AZADIND', 'isin' => 'INE566M01017', 'price_date' => '2026-10-22']);
        $matcher = $this->historicalMatcher();
        foreach ([
            [$this->stock(1, ['symbol' => 'AZADIND', 'isin' => 'INE999A01010'])],
            [$this->stock(1, ['symbol' => 'AZADIND', 'isin' => 'INE566M01025']),
                $this->stock(2, ['symbol' => 'OTHER', 'isin' => 'INE566M01025'])],
            [$this->stock(1, ['symbol' => 'AZADIND', 'isin' => 'INE566M01025']),
                $this->stock(2, ['symbol' => 'OTHER', 'isin' => 'INE566M01017'])],
        ] as $stocks) {
            $result = $matcher->match(collect($stocks), [$row], 'NSE');
            $this->assertSame([], $result['rows_by_stock_id']);
            $this->assertSame([], $result['historical_stock_ids']);
        }
        $bse = $this->stock(1, ['symbol' => 'AZADIND', 'exchange' => 'BSE', 'isin' => 'INE566M01025', 'bse_scrip_code' => '1001']);
        $this->assertSame([], $matcher->match(collect([$bse]), [$row], 'BSE')['rows_by_stock_id']);
    }
}
