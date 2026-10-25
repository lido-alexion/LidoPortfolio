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
}
