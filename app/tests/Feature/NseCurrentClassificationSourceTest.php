<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockClassificationObservation;
use App\Models\StockClassificationRefreshState;
use App\Models\User;
use App\Models\V8\MlUniverseMembership;
use App\Services\StockClassificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NseCurrentClassificationSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        config(['api_failure_reporting.enabled' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-27 05:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stock(array $extra = []): Stock
    {
        return Stock::query()->create(array_replace([
            'symbol' => 'INFY', 'name' => 'Infosys Limited', 'exchange' => 'NSE',
            'series' => 'EQ', 'isin' => 'INE009A01021', 'is_active' => true, 'is_benchmark' => false,
        ], $extra))->fresh();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/nse-current-quote-'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fakeSource(array $metadata, mixed $quote, int $metadataStatus = 200, int $quoteStatus = 200): void
    {
        Http::fake(function ($request) use ($metadata, $quote, $metadataStatus, $quoteStatus) {
            if ($request->url() === 'https://www.nseindia.com/' || $request->url() === 'https://www.nseindia.com/api/marketStatus') {
                return Http::response([], 200);
            }
            $this->assertStringStartsWith(StockClassificationService::CURRENT_SOURCE_URL.'?', $request->url());
            if ($request['functionName'] === 'getMetaData') {
                return Http::response($metadata, $metadataStatus);
            }
            $this->assertSame('getSymbolData', $request['functionName']);

            return Http::response($quote, $quoteStatus);
        });
    }

    private function canonicalEvidence(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        $value = array_map(fn ($item) => $this->canonicalEvidence($item), $value);
        if (! array_is_list($value)) ksort($value, SORT_STRING);

        return $value;
    }

    public function test_real_current_protocol_records_exact_source_and_preserves_first_observed_identity_and_membership(): void
    {
        $stock = $this->stock();
        $before = $stock->getAttributes();
        $membership = MlUniverseMembership::query()->create([
            'stock_id' => $stock->id, 'universe_key' => 'official_nse', 'effective_from' => '2026-10-01',
            'sector_snapshot' => null, 'source' => 'nse_cash_bhavcopy',
        ])->fresh();
        $membershipBefore = $membership->getAttributes();
        $metadata = $this->fixture('metadata');
        $quote = $this->fixture('symbol-data');
        $this->fakeSource($metadata, $quote);
        $first = now()->copy();
        $service = app(StockClassificationService::class);

        $result = $service->refresh($stock, $first);

        $this->assertSame('Information Technology', $result['sector']);
        $this->assertSame('IT Services', $result['industry']);
        $row = StockClassificationObservation::query()->sole();
        $expected = ['quote_metadata' => $metadata, 'equity_quote' => $quote];
        // Native MySQL JSON may reorder object keys; preserve all scalar types/values and list order.
        $this->assertSame($this->canonicalEvidence($expected), $this->canonicalEvidence($row->raw_evidence));
        $this->assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $row->raw_evidence_sha256);
        $this->assertSame('IT - Software', $row->provider_industry);
        $this->assertSame(StockClassificationService::TAXONOMY, $row->taxonomy_version);
        $this->assertSame(StockClassificationService::CURRENT_SOURCE_URL.'?functionName=getSymbolData&marketType=N&series=EQ&symbol=INFY', $row->source_url);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => ($request['functionName'] ?? null) === 'getMetaData' && $request['symbol'] === 'INFY');
        Http::assertSent(fn ($request) => ($request['functionName'] ?? null) === 'getSymbolData' && $request['marketType'] === 'N'
            && $request['series'] === 'EQ' && $request['symbol'] === 'INFY');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/quote-equity'));

        $service->refresh($stock, $first->copy()->addDay());
        $this->assertDatabaseCount('stox_stock_classification_observations', 1);
        $stored = $row->fresh();
        $this->assertTrue($stored->first_observed_at->equalTo($first));
        $this->assertTrue($stored->observed_at->equalTo($first->copy()->addDay()));
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertSame($membershipBefore, $membership->fresh()->getAttributes());
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $state = StockClassificationRefreshState::query()->sole();
        $this->assertSame(0, (int) $state->attempts);
        $this->assertNotNull($state->last_successful_at);
        $this->assertTrue($state->next_attempt_at->equalTo(now()->addDays(7)));
    }

    public function test_current_observation_does_not_override_manual_selection_or_guess_basic_industry(): void
    {
        $stock = $this->stock();
        $admin = User::factory()->admin()->create();
        $service = app(StockClassificationService::class);
        $service->saveOverride($stock, $admin->id, 'Financial Services', 'Banks', 'Existing manual choice');
        $quote = $this->fixture('symbol-data');
        $quote['equityResponse'][0]['secInfo']['basicIndustry'] = 'Deliberately unrelated basic industry';
        $this->fakeSource($this->fixture('metadata'), $quote);

        $result = $service->refresh($stock);

        $this->assertSame('manual_override', $result['source_type']);
        $this->assertSame('Financial Services', $result['sector']);
        $this->assertSame('Banks', $result['industry']);
        $this->assertDatabaseHas('stox_stock_classification_observations', ['stock_id' => $stock->id, 'industry' => 'IT Services']);
        $this->assertDatabaseCount('stox_stock_classification_override_revisions', 1);
        $this->assertSame('IT Services', $service->removeOverride($stock, $admin->id)['industry']);
    }

    public function test_current_source_keeps_the_existing_software_products_industry_distinct(): void
    {
        $stock = $this->stock();
        $quote = $this->fixture('symbol-data');
        $quote['equityResponse'][0]['secInfo']['industryInfo'] = 'Software Products';
        $this->fakeSource($this->fixture('metadata'), $quote);

        $result = app(StockClassificationService::class)->refresh($stock);

        $this->assertSame('Information Technology', $result['sector']);
        $this->assertSame('Software Products', $result['industry']);
        $this->assertSame('Software Products', StockClassificationObservation::query()->sole()->provider_industry);
    }

    #[DataProvider('seriesCases')]
    public function test_series_is_resolved_from_stored_identity_and_offered_metadata(?string $stored, array $offered, string $expected): void
    {
        $stock = $this->stock(['series' => $stored]);
        $metadata = $this->fixture('metadata');
        $metadata['activeSeries'] = $offered;
        $quote = $this->fixture('symbol-data');
        $quote['equityResponse'][0]['metaData']['series'] = $expected;
        $this->fakeSource($metadata, $quote);

        app(StockClassificationService::class)->refresh($stock);

        Http::assertSent(fn ($request) => ($request['functionName'] ?? null) === 'getSymbolData' && $request['series'] === $expected);
        $this->assertSame($stored, $stock->fresh()->series);
    }

    public static function seriesCases(): array
    {
        return [
            'stored BE remains BE' => ['BE', ['EQ', 'BE'], 'BE'],
            'blank ordinary series chooses offered EQ' => [null, ['EQ', 'T0'], 'EQ'],
            'single offered alternative is explicit' => [null, ['BE'], 'BE'],
        ];
    }

    #[DataProvider('invalidSourceCases')]
    public function test_invalid_current_source_is_a_bounded_failure_without_observation_or_success(string $case): void
    {
        $extra = $case === 'ambiguous unselected series' ? ['series' => null] : [];
        if ($case === 'conflicting current master ISIN') $extra['isin'] = 'INE046M01036';
        $stock = $this->stock($extra);
        $before = $stock->getAttributes();
        $metadata = $this->fixture('metadata');
        $quote = $this->fixture('symbol-data');
        $metadataStatus = $quoteStatus = 200;
        switch ($case) {
            case 'metadata symbol mismatch': $metadata['symbol'] = 'OTHER'; break;
            case 'metadata ISIN malformed': $metadata['isin'] = 'invalid'; break;
            case 'missing selected series': $metadata['activeSeries'] = ['BE']; break;
            case 'ambiguous unselected series': $metadata['activeSeries'] = ['BE', 'SM']; break;
            case 'invalid market selector': $metadata['marketType'] = ['N']; break;
            case 'quote symbol mismatch': $quote['equityResponse'][0]['metaData']['symbol'] = 'OTHER'; break;
            case 'quote ISIN mismatch': $quote['equityResponse'][0]['metaData']['isinCode'] = 'INE046M01036'; break;
            case 'quote series mismatch': $quote['equityResponse'][0]['metaData']['series'] = 'BE'; break;
            case 'quote market mismatch': $quote['equityResponse'][0]['metaData']['marketType'] = 'OTHER'; break;
            case 'ambiguous quote rows': $quote['equityResponse'][] = $quote['equityResponse'][0]; break;
            case 'empty quote wrapper': $quote = ['equityResponse' => []]; break;
            case 'legacy shape on current route': $quote = ['industryInfo' => ['sector' => 'Technology', 'industry' => 'Software']]; break;
            case 'malformed JSON 200': $quote = '<html>not JSON</html>'; break;
            case 'missing industry': unset($quote['equityResponse'][0]['secInfo']['industryInfo']); break;
            case 'unknown industry': $quote['equityResponse'][0]['secInfo']['industryInfo'] = 'Unreviewed industry'; break;
            case 'HTTP 403': $quote = '<html>Access Denied</html>'; $quoteStatus = 403; break;
            case 'metadata HTTP 403': $metadataStatus = 403; break;
        }
        $this->fakeSource($metadata, $quote, $metadataStatus, $quoteStatus);

        try {
            app(StockClassificationService::class)->refresh($stock);
            $this->fail('Invalid source must not become a successful classification.');
        } catch (\RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
        }

        $this->assertDatabaseCount('stox_stock_classification_observations', 0);
        $state = StockClassificationRefreshState::query()->sole();
        $this->assertSame(1, (int) $state->attempts);
        $this->assertNull($state->last_successful_at);
        $this->assertTrue($state->next_attempt_at->equalTo(now()->addMinutes(15)));
        $this->assertNotNull($state->last_error);
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        if (in_array($case, ['metadata symbol mismatch', 'metadata ISIN malformed', 'conflicting current master ISIN',
            'missing selected series', 'ambiguous unselected series', 'invalid market selector', 'metadata HTTP 403'], true)) {
            Http::assertNotSent(fn ($request) => ($request['functionName'] ?? null) === 'getSymbolData');
        }
    }

    public static function invalidSourceCases(): array
    {
        $cases = [
            'metadata symbol mismatch', 'metadata ISIN malformed', 'conflicting current master ISIN',
            'missing selected series', 'ambiguous unselected series', 'invalid market selector',
            'quote symbol mismatch', 'quote ISIN mismatch', 'quote series mismatch', 'quote market mismatch',
            'ambiguous quote rows', 'empty quote wrapper', 'legacy shape on current route', 'malformed JSON 200',
            'missing industry', 'unknown industry', 'HTTP 403', 'metadata HTTP 403',
        ];

        return array_combine($cases, array_map(fn ($case) => [$case], $cases));
    }
}
