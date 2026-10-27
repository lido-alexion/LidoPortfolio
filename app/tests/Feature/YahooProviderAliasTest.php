<?php

namespace Tests\Feature;

use App\Models\ApiFailureIncident;
use App\Models\Stock;
use App\Services\PriceFetchService;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Services\ProviderResolverService;
use App\Support\PriceProviderRequestContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooProviderAliasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        config(['api_failure_reporting.enabled' => false]);
    }

    private function stock(array $attributes = []): Stock
    {
        return new Stock(array_replace([
            'symbol' => 'TEAM24', 'name' => 'Team24 Consumer Products Limited',
            'exchange' => 'BSE', 'bse_scrip_code' => '500458',
            'isin' => null, 'is_benchmark' => false, 'is_active' => true,
            'yahoo_symbol' => 'TEAM24.BO', 'alpha_vantage_symbol' => 'TEAM24.BO',
        ], $attributes));
    }

    private function rows(): array
    {
        return ['chart' => ['result' => [[
            'timestamp' => [Carbon::parse('2026-10-16 12:00:00', 'Asia/Kolkata')->timestamp],
            'indicators' => ['quote' => [[
                'open' => [26.7], 'high' => [26.7], 'low' => [26],
                'close' => [26.7], 'volume' => [535],
            ]]],
        ]]]];
    }

    public function test_reviewed_alias_resolves_only_the_exact_security_without_changing_any_attributes(): void
    {
        $resolver = app(ProviderResolverService::class);
        foreach ([null, '', 'INE601A01017'] as $isin) {
            foreach ([null, '', 'TEAM24.BO'] as $stored) {
                $stock = $this->stock(['isin' => $isin, 'yahoo_symbol' => $stored]);
                $before = $stock->getAttributes();
                $this->assertSame([
                    'nse' => 'TEAM24', 'bse_bhavcopy' => '500458',
                    'yahoo' => 'KORE.BO', 'alpha_vantage' => 'TEAM24.BO',
                ], $resolver->providerSymbolsForStock($stock));
                $this->assertSame(['KORE.BO', 'TEAM24.BO'], $resolver->yahooSymbolCandidates($stock));
                $this->assertSame('TEAM24.BO', $resolver->providerSymbolsForStock($stock)['alpha_vantage']);
                $this->assertSame($before, $stock->getAttributes());
            }
        }
    }

    public function test_wrong_identity_and_explicit_custom_symbols_do_not_acquire_the_alias(): void
    {
        $resolver = app(ProviderResolverService::class);
        foreach ([
            ['exchange' => 'NSE', 'yahoo_symbol' => null],
            ['symbol' => 'UNRELATED', 'yahoo_symbol' => null],
            ['bse_scrip_code' => '500459'],
            ['bse_scrip_code' => null],
            ['isin' => 'INE046M01036'],
            ['is_benchmark' => true],
            ['yahoo_symbol' => 'CUSTOM.BO'],
        ] as $attributes) {
            $stock = $this->stock($attributes);
            $before = $stock->getAttributes();
            $this->assertNotContains('KORE.BO', $resolver->yahooSymbolCandidates($stock));
            $this->assertNotSame('KORE.BO', $resolver->providerSymbolsForStock($stock)['yahoo']);
            $this->assertSame($before, $stock->getAttributes());
        }

        $this->assertSame(['CUSTOM.BO', 'TEAM24.BO'], $resolver->yahooSymbolCandidates(
            $this->stock(['yahoo_symbol' => 'CUSTOM.BO']),
        ));
        $this->assertSame('CUSTOM.BO', $resolver->providerSymbolsForStock(
            $this->stock(['yahoo_symbol' => 'CUSTOM.BO']),
        )['yahoo']);
        $this->assertSame('TEAM24.BO', $resolver->yahooSymbol('TEAM24', 'BSE'));
        config(['provider_symbol_aliases.yahoo' => []]);
        $this->assertSame(['TEAM24.BO'], $resolver->yahooSymbolCandidates($this->stock()));
    }

    public function test_canonical_history_and_direct_resolved_requests_use_the_reviewed_symbol_without_stock_or_price_writes(): void
    {
        $stock = $this->stock();
        $stock->save();
        $stock = $stock->fresh();
        $before = $stock->getAttributes();
        Http::fake(['*KORE.BO*' => Http::response($this->rows(), 200)]);
        $from = Carbon::parse('2026-10-15', 'Asia/Kolkata');
        $to = Carbon::parse('2026-10-23', 'Asia/Kolkata');

        $result = app(PriceFetchService::class)->fetchFromProvider('yahoo', 'TEAM24', $from, $to, $stock);
        $symbols = app(ProviderResolverService::class)->providerSymbolsForStock($stock);
        $direct = app(YahooPriceProvider::class)->fetchHistorical('TEAM24', $from, $to, $symbols['yahoo']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame([], $result['errors']);
        $this->assertSame($result['rows'], $direct);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/chart/KORE.BO?')
            && (int) $request['period1'] === $from->copy()->startOfDay()->timestamp
            && (int) $request['period2'] === $to->copy()->endOfDay()->timestamp);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chart/TEAM24.BO'));
        $this->assertSame($before, $stock->getAttributes());
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_alias_failure_keeps_actual_provider_correlation_and_existing_canonical_fallback(): void
    {
        $stock = $this->stock();
        $stock->save();
        $stock = $stock->fresh();
        $before = $stock->getAttributes();
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
            if ($event->message === 'Provider HTTP failure evidence') {
                $logs[] = $event->context;
            }
        });
        Http::fake([
            '*KORE.BO*' => Http::response(['chart' => ['error' => ['code' => 'Not Found']]], 404),
            '*TEAM24.BO*' => Http::response($this->rows(), 200),
        ]);

        $result = app(PriceFetchService::class)->fetchFromProvider(
            'yahoo', 'TEAM24', Carbon::parse('2026-10-15'), Carbon::parse('2026-10-23'), $stock,
        );

        $this->assertCount(1, $result['rows']);
        $this->assertSame([], $result['errors']);
        Http::assertSentCount(3);
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame('TEAM24', $log['symbol']);
            $this->assertSame('KORE.BO', $log['provider_symbol']);
            $this->assertSame('chart_not_found', $log['provider_error']);
        }
        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(2, $incident->occurrence_count);
        $this->assertSame(404, $incident->http_status);
        $this->assertSame($logs[1]['request_id'], $incident->trace_id);
        $this->assertSame($before, $stock->getAttributes());
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_exhausted_alias_and_canonical_requests_keep_real_failure_evidence(): void
    {
        $stock = $this->stock();
        $stock->save();
        Http::fake(fn () => Http::response(['chart' => ['error' => ['code' => 'Not Found']]], 404));

        $result = app(PriceFetchService::class)->fetchFromProvider(
            'yahoo', 'TEAM24', Carbon::parse('2026-10-15'), Carbon::parse('2026-10-23'), $stock,
        );

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('yahoo (KORE.BO)', $result['errors'][0]);
        $this->assertStringContainsString('yahoo (TEAM24.BO)', $result['errors'][0]);
        Http::assertSentCount(4);
        $this->assertSame(4, ApiFailureIncident::query()->sole()->occurrence_count);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $this->assertNull(PriceProviderRequestContext::current());
    }
}
