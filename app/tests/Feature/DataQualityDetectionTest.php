<?php

namespace Tests\Feature;

use App\Models\DataQualityIssue;
use App\Models\CorporateActionSourceReceipt;
use App\Models\StockPrice;
use App\Services\DataQualityCorporateActionHeuristicService;
use App\Services\DataQualityCorporateActionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Services\Fundamentals\AI\FundamentalAiUsageService;
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
        $checkpoint = \App\Models\CorporateActionFeedCheckpoint::query()->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame(1, $checkpoint->rows_seen);
        $this->assertNotNull($checkpoint->last_successful_at);
        $evidence = $issue->evidences->first();
        $this->assertSame('TCS', $evidence->evidence_payload['symbol'] ?? null);
        $this->assertSame($checkpoint->payload_hash, $evidence->evidence_payload['source_receipt']['sha256'] ?? null);
        $this->assertSame(0, $evidence->evidence_payload['source_receipt']['row_index'] ?? null);
        $this->assertSame('https://example.test/ca.json', $evidence->evidence_payload['source_receipt']['url'] ?? null);
        $receipt = CorporateActionSourceReceipt::query()->findOrFail($evidence->evidence_payload['source_receipt']['receipt_id']);
        $this->assertSame(hash('sha256', $receipt->rawBody()), $receipt->sha256);
        $this->assertSame($receipt->sha256, $evidence->evidence_payload['source_receipt']['sha256']);
        $this->assertStringContainsString('TCS', $receipt->rawBody());
    }

    public function test_ai_official_source_discovery_runs_after_deterministic_feed_and_stages_untrusted_hashed_evidence(): void
    {
        $this->createDataQualityStock('TCS');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        config(['fundamentals_ai.enabled' => true, 'fundamentals_ai.primary_provider' => 'codex', 'fundamentals_ai.codex.api_key' => 'test-only', 'fundamentals_ai.codex.model' => 'test-model']);
        $candidate = ['symbol' => 'TCS', 'ex_date' => '2026-05-01', 'url' => 'https://www.nseindia.com/archive/TCS.pdf'];
        Http::fake([
            'https://example.test/ca.json' => Http::response([['symbol' => 'TCS', 'action_type' => 'split', 'ratio' => '1:2', 'ex_date' => '2026-05-01']], 200),
            'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['candidates' => [$candidate]])]]], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]], 200),
            'https://www.nseindia.com/archive/TCS.pdf' => Http::response('%PDF-evidence', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();

        $this->assertSame(1, $result['created']);
        $this->assertSame('ok', $result['ai_evidence_discovery']['status']);
        $this->assertSame(1, $result['ai_evidence_discovery']['verified']);
        $issue = DataQualityIssue::query()->firstOrFail();
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->issue_status);
        $evidence = $issue->evidences()->where('evidence_key', 'ai_official_proposal')->firstOrFail();
        $this->assertSame(hash('sha256', '%PDF-evidence'), $evidence->evidence_payload['sha256']);
        $this->assertFalse($evidence->evidence_payload['ai_authority']);
        $this->assertSame('https://www.nseindia.com/archive/TCS.pdf', $evidence->evidence_payload['url']);
        $receipt = CorporateActionSourceReceipt::query()->findOrFail($evidence->evidence_payload['receipt_id']);
        $this->assertSame('%PDF-evidence', $receipt->rawBody());
        $this->assertSame(hash('sha256', '%PDF-evidence'), $receipt->sha256);
        app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();
        $this->assertSame(1, $issue->fresh()->evidences()->where('evidence_key', 'ai_official_proposal')->count(), 'Repeated discovery must not duplicate the same AI source URL.');
    }

    public function test_ai_failure_does_not_block_deterministic_collection(): void
    {
        $this->createDataQualityStock('TCS');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        config(['fundamentals_ai.enabled' => true, 'fundamentals_ai.primary_provider' => 'codex', 'fundamentals_ai.codex.api_key' => 'test-only']);
        Http::fake([
            'https://example.test/ca.json' => Http::response([['symbol' => 'TCS', 'action_type' => 'split', 'ratio' => '1:2', 'ex_date' => '2026-05-01']], 200),
            'api.openai.com/v1/chat/completions' => Http::response([], 503),
        ]);
        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();
        $this->assertSame(1, $result['created']);
        $this->assertSame('unavailable', $result['ai_evidence_discovery']['status']);
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, DataQualityIssue::query()->firstOrFail()->issue_status);
    }

    public function test_ai_zero_credit_budget_does_not_block_feed_or_admin_queue(): void
    {
        $this->createDataQualityStock('TCS');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        config(['fundamentals_ai.enabled' => true]);
        Http::fake(['https://example.test/ca.json' => Http::response([['symbol' => 'TCS', 'action_type' => 'split', 'ratio' => '1:2', 'ex_date' => '2026-05-01']], 200)]);
        $usage = \Mockery::mock(FundamentalAiUsageService::class);
        $usage->shouldReceive('allowInvocation')->once()->with(null)->andReturn(false);
        $this->app->instance(FundamentalAiUsageService::class, $usage);

        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();

        $this->assertSame(1, $result['created']);
        $this->assertSame('unavailable', $result['ai_evidence_discovery']['status']);
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, DataQualityIssue::query()->firstOrFail()->issue_status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_ai_provider_timeout_does_not_block_deterministic_collection(): void
    {
        $this->createDataQualityStock('TCS');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        config(['fundamentals_ai.enabled' => true, 'fundamentals_ai.primary_provider' => 'codex', 'fundamentals_ai.codex.api_key' => 'test-only']);
        Http::fake([
            'https://example.test/ca.json' => Http::response([['symbol' => 'TCS', 'action_type' => 'split', 'ratio' => '1:2', 'ex_date' => '2026-05-01']], 200),
            'api.openai.com/v1/chat/completions' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Timed out'),
        ]);
        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();
        $this->assertSame(1, $result['created']);
        $this->assertSame('unavailable', $result['ai_evidence_discovery']['status']);
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, DataQualityIssue::query()->firstOrFail()->issue_status);
    }

    public function test_ai_candidate_url_outside_official_allowlist_is_never_fetched_or_staged(): void
    {
        $this->createDataQualityStock('TCS');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://example.test/ca.json']);
        config(['fundamentals_ai.enabled' => true, 'fundamentals_ai.primary_provider' => 'codex', 'fundamentals_ai.codex.api_key' => 'test-only']);
        Http::fake([
            'https://example.test/ca.json' => Http::response([['symbol' => 'TCS', 'action_type' => 'split', 'ratio' => '1:2', 'ex_date' => '2026-05-01']], 200),
            'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['candidates' => [['symbol' => 'TCS', 'ex_date' => '2026-05-01', 'url' => 'https://evil.test/evidence']]])]]]], 200),
        ]);
        app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();
        $this->assertSame(1, DataQualityIssue::query()->firstOrFail()->evidences()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.test'));
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

    public function test_official_nse_corporate_actions_schema_preserves_source_and_dates(): void
    {
        $this->createDataQualityStock('SBC');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://www.nseindia.com/api/corporates-corporateActions?index=equities']);
        Http::fake([
            'https://www.nseindia.com/api/corporates-corporateActions*' => Http::response([
                [
                    'symbol' => 'SBC',
                    'isin' => 'INE04AK01010',
                    'subject' => 'Bonus 1:2',
                    'exDate' => '10-Mar-2025',
                    'recDate' => '10-Mar-2025',
                ],
            ], 200),
        ]);

        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();

        $this->assertSame(1, $result['created']);
        $issue = DataQualityIssue::query()->firstOrFail();
        $this->assertSame('nse_corporate_actions_api', $issue->detection_source);
        $this->assertSame('bonus', $issue->corporate_action_type);
        $this->assertSame('2025-03-10', $issue->ex_date->toDateString());
        $this->assertSame('2025-03-10', $issue->record_date->toDateString());
        $this->assertSame('Bonus 1:2', $issue->raw_payload['subject']);
        $this->assertSame('1:2', $issue->evidences->first()->evidence_value);
        $this->assertSame('INE04AK01010', $issue->evidences->first()->evidence_payload['isin']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'index=equities'));
    }

    public function test_provider_error_payload_does_not_advance_checkpoint(): void
    {
        $this->createDataQualityStock('SBC');
        config(['services.data_quality.corporate_actions_feed_url' => 'https://www.nseindia.com/api/corporates-corporateActions?index=equities']);
        Http::fake([
            'https://www.nseindia.com/api/corporates-corporateActions*' => Http::response([
                'error' => 'Missing index',
            ], 200),
        ]);

        $result = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();

        $this->assertSame(0, $result['synced']);
        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['errors']);
        $checkpoint = \App\Models\CorporateActionFeedCheckpoint::query()->firstOrFail();
        $this->assertSame('payload_not_event_list', $checkpoint->last_error);
        $this->assertNull($checkpoint->last_successful_at);
        $this->assertNull($checkpoint->payload_hash);
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
