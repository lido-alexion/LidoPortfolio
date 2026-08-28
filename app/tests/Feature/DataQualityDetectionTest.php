<?php

namespace Tests\Feature;

use App\Models\DataQualityIssue;
use App\Models\StockPrice;
use App\Services\DataQualityCorporateActionHeuristicService;
use App\Services\DataQualityCorporateActionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityDetectionTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    public function test_exchange_feed_creates_pending_issue(): void
    {
        $stock = $this->createDataQualityStock('TCS');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        Http::fake([
            'https://example.test/ca.json' => Http::response([
                [
                    'symbol' => 'TCS',
                    'action_type' => 'split',
                    'ratio' => '1:2',
                    'ex_date' => '2026-05-01',
                ],
            ], 200),
        ]);

        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();

        $this->assertSame(1, $result['created']);
        $issue = DataQualityIssue::query()->first();
        $this->assertNotNull($issue);
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->issue_status);
        $this->assertSame(DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED, $issue->detection_method);
        $this->assertNotEmpty($result['detection_run_id'] ?? null);
        $this->assertSame($result['detection_run_id'], $issue->evidences->first()->evidence_payload['detection_run_id'] ?? null);
    }

    public function test_exchange_feed_skips_rights_issues(): void
    {
        $stock = $this->createDataQualityStock('RELIANCE');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        Http::fake([
            'https://example.test/ca.json' => Http::response([
                [
                    'symbol' => 'RELIANCE',
                    'action_type' => 'rights',
                    'ratio' => '1:1',
                    'ex_date' => '2026-05-01',
                ],
                [
                    'symbol' => 'RELIANCE',
                    'type' => 'rights_issue',
                    'ratio' => '3:1',
                    'ex_date' => '2026-06-01',
                ],
            ], 200),
        ]);

        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();

        $this->assertSame(2, $result['synced']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, DataQualityIssue::query()->count());
        $this->assertSame('RELIANCE', $stock->symbol);
    }

    public function test_heuristic_detection_creates_pending_issue_with_run_id(): void
    {
        $stock = $this->createDataQualityStock('GAP');
        $this->seedGapPrices($stock, 200.0, 100.0);

        $result = app(DataQualityCorporateActionHeuristicService::class)->scanAllStocks(25.0);

        $this->assertSame(1, $result['flagged']);
        $issue = DataQualityIssue::query()->where('stock_id', $stock->id)->first();
        $this->assertNotNull($issue);
        $this->assertSame(DataQualityIssue::DETECTION_METHOD_HEURISTIC_GAP, $issue->detection_method);
        $this->assertSame($result['detection_run_id'], $issue->evidences->first()->evidence_payload['detection_run_id'] ?? null);
    }

    public function test_heuristic_detection_does_not_modify_ohlcv(): void
    {
        $stock = $this->createDataQualityStock('OHLCV');
        $this->seedGapPrices($stock, 200.0, 100.0);

        app(DataQualityCorporateActionHeuristicService::class)->scanStock($stock, 25.0);

        $open = StockPrice::query()->where('stock_id', $stock->id)->orderByDesc('price_date')->value('open_price');
        $this->assertSame('100.0000', number_format((float) $open, 4, '.', ''));
    }
}
