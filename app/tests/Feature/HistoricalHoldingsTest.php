<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PortfolioHistoricalHoldingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalHoldingsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): array
    {
        $user = User::query()->create([
            'name' => 'F014 User',
            'email' => 'f014-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        return [$user, $profile];
    }

    protected function makeStock(string $symbol = 'F014A'): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol.' Co',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function addPrice(
        Stock $stock,
        string $date,
        float $close,
        ?float $adjustedClose = null,
    ): void {
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'adjusted_close_price' => $adjustedClose,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);
    }

    protected function addTx(
        $profile,
        Stock $stock,
        string $type,
        float $qty,
        float $price,
        string $date,
        float $fees = 0,
    ): Transaction {
        return Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => $type,
            'quantity' => $qty,
            'price' => $price,
            'fees' => $fees,
            'transaction_date' => $date,
        ]);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01')
            ->assertUnauthorized();
    }

    public function test_future_as_of_is_rejected(): void
    {
        [$user] = $this->makeUser();
        $future = now()->addDay()->toDateString();

        $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of='.$future)
            ->assertStatus(422);
    }

    public function test_pre_inception_returns_200_empty_list(): void
    {
        [$user, $profile] = $this->makeUser();
        $stock = $this->makeStock('EMPTY');
        $this->addTx($profile, $stock, 'buy', 1, 10, '2026-03-01');

        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01');

        $response->assertOk()
            ->assertJsonPath('as_of', '2026-02-01')
            ->assertJsonPath('holdings', [])
            ->assertJsonPath('completeness.holding_count', 0)
            ->assertJsonPath('completeness.valuation_complete', true);

        $this->assertArrayNotHasKey('cash', $response->json());
        $this->assertArrayNotHasKey('realized_profit', $response->json());
        $this->assertArrayNotHasKey('realized_pl', $response->json());
    }

    public function test_includes_transactions_on_as_of_and_excludes_later(): void
    {
        [$user, $profile] = $this->makeUser();
        $stock = $this->makeStock('INC');
        $this->addTx($profile, $stock, 'buy', 5, 100, '2026-02-01', fees: 7);
        $this->addTx($profile, $stock, 'buy', 5, 100, '2026-02-10');
        $this->addPrice($stock, '2026-02-01', 110);

        $onDate = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01');

        $onDate->assertOk()
            ->assertJsonPath('holdings.0.quantity', 5)
            ->assertJsonPath('holdings.0.invested_amount', 500)
            ->assertJsonPath('holdings.0.avg_buy_price', 100);

        // Fees excluded from invested (PD-04).
        $this->assertSame(500.0, (float) $onDate->json('holdings.0.invested_amount'));

        $beforeSecond = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-05');
        $beforeSecond->assertJsonPath('holdings.0.quantity', 5);

        $afterSecond = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-10');
        $afterSecond->assertJsonPath('holdings.0.quantity', 10);
    }

    public function test_partial_sell_and_same_day_order_by_id(): void
    {
        [$user, $profile] = $this->makeUser();
        $stock = $this->makeStock('ORD');
        $buy = $this->addTx($profile, $stock, 'buy', 10, 50, '2026-02-03');
        $sell = $this->addTx($profile, $stock, 'sell', 4, 60, '2026-02-03');
        $this->assertTrue($buy->id < $sell->id);
        $this->addPrice($stock, '2026-02-03', 55);

        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-03');

        $response->assertOk()
            ->assertJsonPath('holdings.0.quantity', 6)
            ->assertJsonPath('holdings.0.invested_amount', 300);
    }

    public function test_oversell_warning_does_not_fail_request(): void
    {
        [$user, $profile] = $this->makeUser();
        $good = $this->makeStock('GOOD');
        $bad = $this->makeStock('BAD');
        $this->addTx($profile, $good, 'buy', 2, 10, '2026-02-01');
        $this->addTx($profile, $bad, 'sell', 5, 10, '2026-02-02');
        $this->addPrice($good, '2026-02-02', 12);

        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-02');

        $response->assertOk();
        $rows = collect($response->json('holdings'))->keyBy('symbol');
        $this->assertTrue($rows->has('GOOD'));
        $this->assertSame(2.0, (float) $rows['GOOD']['quantity']);
        $this->assertFalse($rows->has('BAD'));

        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertSame('historical_oversell', $warnings[0]['code']);
        $this->assertSame('BAD', $warnings[0]['symbol']);
    }

    public function test_valuation_prefers_adjusted_close_and_prior_session_on_weekend(): void
    {
        [$user, $profile] = $this->makeUser();
        $stock = $this->makeStock('PX');
        $this->addTx($profile, $stock, 'buy', 2, 100, '2026-02-06'); // Friday
        $this->addPrice($stock, '2026-02-06', 100, adjustedClose: 200);
        // Saturday as_of should use Friday adjusted close.
        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-07');

        $response->assertOk()
            ->assertJsonPath('holdings.0.as_of_price', 200)
            ->assertJsonPath('holdings.0.market_value', 400)
            ->assertJsonPath('holdings.0.unrealized_profit', 200)
            ->assertJsonPath('holdings.0.unrealized_gain_percent', 100)
            ->assertJsonPath('completeness.valuation_complete', true)
            ->assertJsonPath('totals.valuation_complete', true);
    }

    public function test_missing_price_marks_incomplete_and_null_values(): void
    {
        [$user, $profile] = $this->makeUser();
        $priced = $this->makeStock('HASPX');
        $missing = $this->makeStock('NOPX');
        $this->addTx($profile, $priced, 'buy', 1, 50, '2026-02-01');
        $this->addTx($profile, $missing, 'buy', 1, 50, '2026-02-01');
        $this->addPrice($priced, '2026-02-01', 60);

        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01');

        $response->assertOk()
            ->assertJsonPath('completeness.valuation_complete', false)
            ->assertJsonPath('completeness.missing_price_count', 1)
            ->assertJsonPath('totals.market_value', null)
            ->assertJsonPath('totals.unrealized_profit', null);

        $rows = collect($response->json('holdings'))->keyBy('symbol');
        $this->assertNull($rows['NOPX']['as_of_price']);
        $this->assertNull($rows['NOPX']['market_value']);
        $this->assertNull($rows['NOPX']['unrealized_profit']);
        $this->assertFalse($rows['NOPX']['price_available']);
        $this->assertSame(60.0, (float) $rows['HASPX']['as_of_price']);
    }

    public function test_foreign_profile_cannot_see_another_users_holdings(): void
    {
        [$userA, $profileA] = $this->makeUser();
        [$userB] = $this->makeUser();
        $stock = $this->makeStock('PRIV');
        $this->addTx($profileA, $stock, 'buy', 3, 10, '2026-02-01');
        $this->addPrice($stock, '2026-02-01', 12);

        $response = $this->actingAs($userB)
            ->withHeader('X-Profile-Id', (string) $profileA->id)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01');

        $this->assertTrue(in_array($response->status(), [403, 404, 422], true));
        if ($response->status() === 200) {
            $this->assertSame([], $response->json('holdings'));
        }
    }

    public function test_ledger_truth_after_quantity_correction(): void
    {
        [$user, $profile] = $this->makeUser();
        $stock = $this->makeStock('CA');
        $tx = $this->addTx($profile, $stock, 'buy', 10, 100, '2026-01-15');
        // Simulate post-CA ledger rewrite (split 2:1 → qty×2, price/2).
        $tx->forceFill(['quantity' => 20, 'price' => 50])->save();
        $this->addPrice($stock, '2026-02-01', 55, adjustedClose: 55);

        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01');

        $response->assertOk()
            ->assertJsonPath('holdings.0.quantity', 20)
            ->assertJsonPath('holdings.0.avg_buy_price', 50)
            ->assertJsonPath('holdings.0.invested_amount', 1000);
    }

    public function test_holdings_as_of_remains_compatible_for_f015_callers(): void
    {
        $service = app(PortfolioHistoricalHoldingsService::class);
        $txs = new Collection([
            (object) [
                'id' => 1,
                'stock_id' => 9,
                'type' => 'buy',
                'quantity' => 2,
                'price' => 10,
                'transaction_date' => '2026-01-01',
            ],
            (object) [
                'id' => 2,
                'stock_id' => 9,
                'type' => 'sell',
                'quantity' => 99,
                'price' => 10,
                'transaction_date' => '2026-01-02',
            ],
        ]);

        $map = $service->holdingsAsOf($txs, Carbon::parse('2026-01-03'));
        $this->assertEqualsWithDelta(2, $map[9]['quantity'], 0.0001);

        $detailed = $service->holdingsAsOfDetailed($txs, Carbon::parse('2026-01-03'));
        $this->assertCount(1, $detailed['warnings']);
        $this->assertSame('historical_oversell', $detailed['warnings'][0]['code']);
    }

    public function test_does_not_use_live_holdings_table_for_quantities(): void
    {
        [$user, $profile] = $this->makeUser();
        $stock = $this->makeStock('LEDGER');
        $this->addTx($profile, $stock, 'buy', 4, 25, '2026-02-01');
        $this->addPrice($stock, '2026-02-01', 30);

        // Poison live holdings with a different quantity — F014 must ignore it.
        \App\Models\Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 999,
            'avg_buy_price' => 1,
            'invested_amount' => 999,
            'total_fees' => 0,
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/portfolio/historical-holdings?as_of=2026-02-01');

        $response->assertOk()->assertJsonPath('holdings.0.quantity', 4);
    }
}
