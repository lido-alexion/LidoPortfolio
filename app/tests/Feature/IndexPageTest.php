<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndexPageTest extends TestCase
{
    use RefreshDatabase;

    protected function createBenchmark(string $symbol, string $exchange = 'NSE'): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => $exchange,
            'name' => $symbol,
            'is_active' => true,
            'is_benchmark' => true,
            'yahoo_symbol' => '^TEST',
        ]);
    }

    protected function weekdayDate(int $daysAgo): string
    {
        $d = now()->subDays($daysAgo)->copy()->startOfDay();
        while ($d->isWeekend()) {
            $d->subDay();
        }

        return $d->toDateString();
    }

    protected function seedPrices(Stock $stock, float $startClose, float $endClose): void
    {
        $start = $this->weekdayDate(370);
        $end = $this->weekdayDate(1);

        foreach ([$start, $end] as $i => $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'close_price' => $i === 0 ? $startClose : $endClose,
                'adjusted_close_price' => $i === 0 ? $startClose : $endClose,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }

    public function test_page_lists_broad_indexes_with_metadata(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $user = User::query()->create([
            'name' => 'Index User',
            'email' => 'idx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $nifty = $this->createBenchmark('NIFTY50');
        $bank = $this->createBenchmark('NIFTYBANK');
        $this->seedPrices($nifty, 100, 110);
        $this->seedPrices($bank, 200, 220);

        $response = $this->actingAs($user)
            ->getJson('/api/indexes/page')
            ->assertOk();

        $symbols = collect($response->json('data.indexes'))->pluck('symbol')->all();
        $this->assertContains('NIFTY50', $symbols);
        $this->assertContains('SENSEX', $symbols);
        $this->assertContains('NIFTYBANK', $symbols);
        $this->assertContains('INDIAVIX', $symbols);

        $niftyRow = collect($response->json('data.indexes'))->firstWhere('symbol', 'NIFTY50');
        $this->assertSame($nifty->id, $niftyRow['stock_id']);
        $this->assertNotEmpty($niftyRow['description']);
        $this->assertTrue($niftyRow['constituents_available']);
        $this->assertSame('broad', $niftyRow['tier']);
        $this->assertSame(110.0, (float) $niftyRow['latest_close']);

        $bankRow = collect($response->json('data.indexes'))->firstWhere('symbol', 'NIFTYBANK');
        $this->assertSame('sector', $bankRow['tier']);

        $vixRow = collect($response->json('data.indexes'))->firstWhere('symbol', 'INDIAVIX');
        $this->assertSame('volatility', $vixRow['tier']);
        $this->assertFalse($vixRow['constituents_available']);
    }

    public function test_comparison_returns_normalized_series(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $user = User::query()->create([
            'name' => 'Index User',
            'email' => 'idx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $nifty = $this->createBenchmark('NIFTY50');
        $sensex = $this->createBenchmark('SENSEX', 'BSE');
        $bank = $this->createBenchmark('NIFTYBANK');
        $this->seedPrices($nifty, 100, 120);
        $this->seedPrices($sensex, 1000, 1100);
        $this->seedPrices($bank, 200, 240);

        $response = $this->actingAs($user)
            ->getJson('/api/indexes/comparison?months=12')
            ->assertOk();

        $series = collect($response->json('data.series'));
        $this->assertTrue($series->pluck('symbol')->contains('NIFTY50'));
        $this->assertTrue($series->pluck('symbol')->contains('SENSEX'));
        $this->assertTrue($series->pluck('symbol')->contains('NIFTYBANK'));
        $this->assertFalse($series->pluck('symbol')->contains('INDIAVIX'));

        $niftySeries = $series->firstWhere('symbol', 'NIFTY50');
        $lastPoint = end($niftySeries['points']);
        $this->assertSame(20.0, (float) $lastPoint['gain_percent']);
    }

    public function test_constituents_endpoint_returns_enriched_symbols(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $user = User::query()->create([
            'name' => 'Index User',
            'email' => 'idx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        Stock::query()->create([
            'symbol' => 'RELIANCE',
            'exchange' => 'NSE',
            'name' => 'Reliance Industries',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Http::fake([
            'nsearchives.nseindia.com/*' => Http::response(
                "Company Name,Industry,Symbol,Series,ISIN Code\nReliance Industries Ltd.,...,RELIANCE,EQ,INE002A01018\n",
                200,
                ['Content-Type' => 'text/csv'],
            ),
            'archives.nseindia.com/*' => Http::response('', 404),
            'www.nseindia.com/*' => Http::response([
                'data' => [
                    ['symbol' => 'RELIANCE'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/indexes/NIFTY50/constituents')
            ->assertOk();

        $response->assertJsonPath('data.available', true);
        $response->assertJsonPath('data.constituents.0.symbol', 'RELIANCE');
        $response->assertJsonPath('data.constituents.0.name', 'Reliance Industries');
    }

    public function test_bse_index_constituents_are_unavailable(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $user = User::query()->create([
            'name' => 'Index User',
            'email' => 'idx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user)
            ->getJson('/api/indexes/SENSEX/constituents')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.constituents', []);
    }
}
