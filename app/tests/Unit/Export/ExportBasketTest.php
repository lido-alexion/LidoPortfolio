<?php

namespace Tests\Unit\Export;

use App\Models\ExportBasket;
use Tests\TestCase;

class ExportBasketTest extends TestCase
{
    public function test_new_baskets_default_to_an_empty_items_array_without_a_database_default(): void
    {
        $basket = new ExportBasket();

        $this->assertSame([], $basket->items);
    }
}
