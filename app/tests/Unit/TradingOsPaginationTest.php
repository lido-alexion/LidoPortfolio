<?php

namespace Tests\Unit;

use App\Support\TradingOsPagination;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class TradingOsPaginationTest extends TestCase
{
    public function test_resolve_defaults_and_aliases(): void
    {
        $defaults = TradingOsPagination::resolve(Request::create('/x', 'GET'), 50);
        $this->assertSame(['page' => 1, 'pageSize' => 50], $defaults);

        $aliased = TradingOsPagination::resolve(Request::create('/x?page=3&per_page=25', 'GET'), 50);
        $this->assertSame(['page' => 3, 'pageSize' => 25], $aliased);

        $pageSizeWins = TradingOsPagination::resolve(Request::create('/x?pageSize=10&per_page=99', 'GET'), 50);
        $this->assertSame(['page' => 1, 'pageSize' => 10], $pageSizeWins);
    }

    public function test_resolve_clamps_page_and_page_size(): void
    {
        $zeroPage = TradingOsPagination::resolve(Request::create('/x?page=0&pageSize=0', 'GET'), 40);
        $this->assertSame(['page' => 1, 'pageSize' => 40], $zeroPage);

        $clamped = TradingOsPagination::resolve(Request::create('/x?pageSize=999', 'GET'), 50);
        $this->assertSame(200, $clamped['pageSize']);

        $priceBars = TradingOsPagination::resolve(
            Request::create('/x?pageSize=999', 'GET'),
            100,
            TradingOsPagination::PRICE_BARS_MAX_PAGE_SIZE,
        );
        $this->assertSame(500, $priceBars['pageSize']);
    }

    public function test_meta_uses_paginator_values(): void
    {
        $paginator = new LengthAwarePaginator(['a', 'b'], 25, 10, 2);

        $this->assertSame([
            'page' => 2,
            'pageSize' => 10,
            'total' => 25,
            'lastPage' => 3,
        ], TradingOsPagination::meta($paginator));
    }

    public function test_clamp_helpers_match_engine_bounds(): void
    {
        $this->assertSame(1, TradingOsPagination::clampPage(0));
        $this->assertSame(1, TradingOsPagination::clampPage(-4));
        $this->assertSame(3, TradingOsPagination::clampPage(3));

        $this->assertSame(1, TradingOsPagination::clampPageSize(0));
        $this->assertSame(200, TradingOsPagination::clampPageSize(999));
        $this->assertSame(500, TradingOsPagination::clampPageSize(999, TradingOsPagination::PRICE_BARS_MAX_PAGE_SIZE));
        $this->assertSame(40, TradingOsPagination::clampPageSize(40));
    }
}
