<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V7\FundamentalFact;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\ExchangeRequestDeferred;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfficialFundamentalMetadataTest extends TestCase
{
    use RefreshDatabase;

    public static function exchanges(): array
    {
        return [['NSE'], ['BSE']];
    }

    private function service(string $exchange, array $yahoo = []): FundamentalHistoricalIngestService
    {
        FundamentalSetting::query()->updateOrCreate([], ['nse_official_fallback_enabled' => true, 'bse_official_fallback_enabled' => true]);
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.bse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse',
            'fundamentals_bootstrap.bse_official_feed_url' => 'https://feeds.example/bse',
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn($yahoo);

        return new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );
    }

    private function row(array $extra = []): array
    {
        return array_replace([
            'statement_type' => 'income_statement',
            'cadence' => 'quarterly',
            'fact_key' => 'revenue',
            'period_end' => '2024-03-31',
            'value' => 100,
            'availability_date' => '2024-05-15',
        ], $extra);
    }

    // Sort JSON object keys recursively, preserving list order and strict scalar types.
    private function orderedObjects(array $value): array
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $child = $this->orderedObjects($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    #[DataProvider('exchanges')]
    public function test_supplied_metadata_and_distinct_bases_survive_ingest_and_storage(string $exchange): void
    {
        // Synthetic regression values: not historical-coverage or qualification evidence.
        $metadata = ['reported_fiscal_period' => 'FY2024 Q4', 'period_kind' => 'quarter',
            'availability_quality' => 'exact', 'document_id' => 'fixture-document',
            'context' => ['cumulative' => false, 'currency' => 'INR', 'columns' => ['quarter', 'YTD'], 'count' => 2, 'missing' => null]];
        $standaloneMeta = array_replace($metadata, ['period_kind' => 'YTD', 'context' => ['cumulative' => true]]);
        $official = [
            $this->row(['statement_basis' => 'consolidated', 'period_start' => '2024-01-01', 'source_meta' => $metadata]),
            $this->row(['statement_basis' => 'standalone', 'period_start' => '2023-04-01', 'source_meta' => $standaloneMeta]),
            $this->row(['statement_basis' => 'unknown', 'period_start' => null]),
        ];
        // Unsupported provider payload and fiscal date field must not be copied wholesale.
        $official[0]['raw_payload'] = 'PRIVATE-PAYLOAD';
        $official[0]['reported_period'] = 'FY2024 Q4';
        Http::fake(['feeds.example/*' => Http::response(['facts' => $official])]);
        $service = $this->service($exchange);
        $stock = Stock::query()->create(['symbol' => 'META', 'exchange' => $exchange, 'name' => 'Fixture']);
        $rows = $service->fetch($stock, 'quarterly', true);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(strtolower($exchange).'_official', $row['provider']);
            $this->assertSame(100, $row['value']);
            $this->assertArrayNotHasKey('raw_payload', $row);
            $this->assertArrayNotHasKey('reported_period', $row);
        }
        $this->assertSame(['inserted' => 3, 'deduped' => 0, 'restated' => 0], app(FundamentalDataService::class)->storeFacts($stock, $rows));
        $facts = FundamentalFact::where('stock_id', $stock->id)->get()->keyBy('statement_basis');
        $this->assertCount(3, $facts);
        $this->assertSame($this->orderedObjects($metadata), $this->orderedObjects($facts['consolidated']->source_meta));
        $this->assertSame($this->orderedObjects($standaloneMeta), $this->orderedObjects($facts['standalone']->source_meta));
        $this->assertSame('2024-01-01', $facts['consolidated']->period_start->toDateString());
        $this->assertSame('2023-04-01', $facts['standalone']->period_start->toDateString());
        $this->assertNull($facts['unknown']->period_start);
        $this->assertSame('2024-03-31', $facts['consolidated']->reported_period->toDateString());
        $this->assertSame('2024-05-15', $facts['consolidated']->availability_date->toDateString());
    }

    #[DataProvider('exchanges')]
    public function test_unproven_official_basis_stays_unknown_when_used_as_fallback(string $exchange): void
    {
        foreach ([[], ['statement_basis' => null], ...array_map(fn ($basis) => ['statement_basis' => $basis],
            ['', ' ', 'CONSOLIDATED', 'derived', 'provider|private', [], ['consolidated'], ['basis' => 'standalone'], true, false, 1, 0, 1.5])] as $index => $optional) {
            Http::swap(new Factory);
            Http::fake(['feeds.example/*' => Http::response([$this->row($optional)])]);
            $service = $this->service($exchange);
            $stock = Stock::query()->create(['symbol' => 'ABSENT'.$index, 'exchange' => $exchange, 'name' => 'Fixture']);
            $rows = $service->fetch($stock, 'quarterly', true);
            $this->assertCount(1, $rows);
            $this->assertSame('unknown', $rows[0]['statement_basis']);
            $this->assertSame(100, $rows[0]['value']);
            $this->assertArrayNotHasKey('period_start', $rows[0]);
            $this->assertArrayNotHasKey('source_meta', $rows[0]);
            $this->assertSame('INR', $rows[0]['currency']);
            $this->assertSame(strtolower($exchange).'_official', $rows[0]['provider']);
            app(FundamentalDataService::class)->storeFacts($stock, $rows);
            $facts = FundamentalFact::where('stock_id', $stock->id)->get()->keyBy('statement_basis');
            $this->assertCount(1, $facts);
            $this->assertSame('100.000000', $facts['unknown']->value);
            $this->assertNull($facts['unknown']->period_start);
            $this->assertSame([], $facts['unknown']->source_meta);
        }
    }

    #[DataProvider('exchanges')]
    public function test_fetch_failure_logs_no_exception_payload_or_url(string $exchange): void
    {
        Http::fake(function () {
            throw new \RuntimeException('PRIVATE-PAYLOAD https://user:SECRET@feed.example/private');
        });
        $service = $this->service($exchange);
        try {
            $service->fetch(new Stock(['symbol' => 'META', 'exchange' => $exchange]), 'quarterly', true);
            $this->fail('A failed exchange request should be deferred safely.');
            $this->assertSame('Exchange request deferred: the source request failed', $error->getMessage());
            $this->assertStringNotContainsString('PRIVATE-PAYLOAD', $error->getMessage());
            $this->assertStringNotContainsString('SECRET', $error->getMessage());
            $this->assertStringNotContainsString('feed.example', $error->getMessage());
        }
    }
}
