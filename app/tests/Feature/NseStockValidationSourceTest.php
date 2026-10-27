<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Services\NseEquityQuoteService;
use App\Services\StockValidationService;
use App\Support\StockValidationUserMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NseStockValidationSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        config(['api_failure_reporting.enabled' => false]);
    }

    private function payloads(): array
    {
        $metadata = json_decode(file_get_contents(base_path('tests/Fixtures/nse-current-quote-metadata.json')), true, flags: JSON_THROW_ON_ERROR);
        $quote = json_decode(file_get_contents(base_path('tests/Fixtures/nse-current-quote-symbol-data.json')), true, flags: JSON_THROW_ON_ERROR);
        // The retained full primary response has companyName at this exact metaData key.
        $quote['equityResponse'][0]['metaData']['companyName'] = 'Infosys Limited';

        return [$metadata, $quote];
    }

    private function fakeSource(array $metadata, mixed $quote, int $metadataStatus = 200, int $quoteStatus = 200): void
    {
        Http::fake(function ($request) use ($metadata, $quote, $metadataStatus, $quoteStatus) {
            if (in_array($request->url(), ['https://www.nseindia.com/', 'https://www.nseindia.com/api/marketStatus'], true)) {
                return Http::response([], 200);
            }
            $this->assertStringStartsWith(NseEquityQuoteService::SOURCE_URL.'?', $request->url());
            if ($request['functionName'] === 'getMetaData') {
                return Http::response($metadata, $metadataStatus);
            }
            $this->assertSame('getSymbolData', $request['functionName']);

            return Http::response($quote, $quoteStatus);
        });
    }

    public function test_verified_current_identity_validates_and_upserts_without_requiring_sector_coverage(): void
    {
        [$metadata, $quote] = $this->payloads();
        unset($quote['equityResponse'][0]['secInfo']);
        $this->fakeSource($metadata, $quote);

        $result = app(StockValidationService::class)->validateViaNSE('INFY');

        $this->assertTrue($result->valid);
        $this->assertSame('nse', $result->source);
        $this->assertSame('Infosys Limited', $result->stock->fresh()->name);
        $this->assertSame('INE009A01021', $result->stock->fresh()->isin);
        $this->assertDatabaseCount('portfolio_stocks', 1);
        $this->assertDatabaseCount('stox_stock_classification_observations', 0);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request['functionName'] === 'getSymbolData'
            && $request['symbol'] === 'INFY' && $request['series'] === 'EQ');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/quote-equity'));
    }

    #[DataProvider('nameFallbackCases')]
    public function test_absent_or_null_company_name_preserves_the_verified_symbol_fallback(bool $explicitNull): void
    {
        [$metadata, $quote] = $this->payloads();
        if ($explicitNull) {
            $quote['equityResponse'][0]['metaData']['companyName'] = null;
        } else {
            unset($quote['equityResponse'][0]['metaData']['companyName']);
        }
        $this->fakeSource($metadata, $quote);

        $result = app(StockValidationService::class)->validateViaNSE('INFY');

        $this->assertTrue($result->valid);
        $this->assertSame('INFY', $result->stock->name);
        $this->assertSame('INE009A01021', $result->stock->isin);
    }

    public static function nameFallbackCases(): array
    {
        return ['absent' => [false], 'explicit null' => [true]];
    }

    public function test_existing_series_is_an_identity_expectation_during_valid_reverification(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'INFY', 'name' => 'Prior name', 'exchange' => 'NSE', 'series' => 'BE',
            'isin' => 'INE009A01021', 'is_active' => true, 'is_benchmark' => false,
        ]);
        [$metadata, $quote] = $this->payloads();
        $metadata['activeSeries'] = ['EQ', 'BE'];
        $quote['equityResponse'][0]['metaData']['series'] = 'BE';
        $this->fakeSource($metadata, $quote);

        $result = app(StockValidationService::class)->validateViaNSE('INFY');

        $this->assertTrue($result->valid);
        $this->assertSame($stock->id, $result->stock->id);
        $this->assertSame('BE', $stock->fresh()->series);
        $this->assertSame('INE009A01021', $stock->fresh()->isin);
        $this->assertSame('Infosys Limited', $stock->fresh()->name);
        $this->assertDatabaseCount('portfolio_stocks', 1);
        Http::assertSent(fn ($request) => $request['functionName'] === 'getSymbolData' && $request['series'] === 'BE');
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_source_never_upserts_or_overwrites_an_existing_security(string $case): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'INFY', 'name' => 'Preserve existing name', 'exchange' => 'NSE', 'series' => 'EQ',
            'isin' => $case === 'conflicting master ISIN' ? 'INE046M01036' : 'INE009A01021',
            'is_active' => true, 'is_benchmark' => false,
        ])->fresh();
        $before = $stock->getAttributes();
        [$metadata, $quote] = $this->payloads();
        $metadataStatus = $quoteStatus = 200;
        switch ($case) {
            case 'foreign metadata symbol': $metadata['symbol'] = 'OTHER'; break;
            case 'foreign quote symbol': $quote['equityResponse'][0]['metaData']['symbol'] = 'OTHER'; break;
            case 'foreign quote ISIN': $quote['equityResponse'][0]['metaData']['isinCode'] = 'INE046M01036'; break;
            case 'foreign quote series': $quote['equityResponse'][0]['metaData']['series'] = 'BE'; break;
            case 'unoffered stored series': $metadata['activeSeries'] = ['BE']; break;
            case 'legacy wire shape': $quote = ['info' => ['symbol' => 'INFY', 'companyName' => 'Unverified old shape']]; break;
            case 'malformed company name': $quote['equityResponse'][0]['metaData']['companyName'] = ['not a name']; break;
            case 'empty company name': $quote['equityResponse'][0]['metaData']['companyName'] = ''; break;
            case 'metadata HTTP 403': $metadataStatus = 403; break;
            case 'quote HTTP 403': $quoteStatus = 403; break;
            case 'quote HTTP 503': $quoteStatus = 503; break;
            case 'malformed JSON 200': $quote = '<html>not JSON</html>'; break;
        }
        $this->fakeSource($metadata, $quote, $metadataStatus, $quoteStatus);

        $result = app(StockValidationService::class)->validateViaNSE('INFY');

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertDatabaseCount('portfolio_stocks', 1);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $this->assertDatabaseCount('stox_stock_classification_observations', 0);
        if (in_array($case, ['metadata HTTP 403', 'quote HTTP 403', 'quote HTTP 503'], true)) {
            $status = $case === 'quote HTTP 503' ? '503' : '403';
            $this->assertSame(['NSE: HTTP '.$status], $result->errors);
            $friendly = StockValidationUserMessage::fromErrors($result->errors, 'INFY', 'NSE');
            $this->assertStringContainsString($status === '403'
                ? 'NSE blocked automated access (403)' : 'NSE temporarily unavailable (503)', $friendly);
        }
        if (in_array($case, ['foreign metadata symbol', 'conflicting master ISIN', 'unoffered stored series', 'metadata HTTP 403'], true)) {
            Http::assertNotSent(fn ($request) => $request['functionName'] === 'getSymbolData');
        }
    }

    public static function invalidCases(): array
    {
        $cases = [
            'foreign metadata symbol', 'foreign quote symbol', 'foreign quote ISIN', 'foreign quote series',
            'conflicting master ISIN', 'unoffered stored series', 'legacy wire shape',
            'malformed company name', 'empty company name', 'metadata HTTP 403', 'quote HTTP 403', 'quote HTTP 503', 'malformed JSON 200',
        ];

        return array_combine($cases, array_map(fn ($case) => [$case], $cases));
    }

    public function test_local_only_unknown_and_non_nse_validation_remain_network_free(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'INFY', 'name' => 'Infosys Limited', 'exchange' => 'NSE',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $service = app(StockValidationService::class);

        $local = $service->validate('INFY', 'NSE', false);
        $unknown = $service->validate('NEWCO', 'NSE', false);
        $wrongExchange = $service->validateViaNSE('INFY', 'BSE');

        $this->assertTrue($local->valid);
        $this->assertSame('local', $local->source);
        $this->assertSame($stock->id, $local->stock->id);
        $this->assertFalse($unknown->valid);
        $this->assertFalse($wrongExchange->valid);
        $this->assertDatabaseCount('portfolio_stocks', 1);
        Http::assertNothingSent();
    }
}
