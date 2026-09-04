<?php

namespace Tests\Feature\Tos;

use App\Engines\Data\DataEngine;
use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Execution\ExecutionEngine;
use App\Engines\Notification\NotificationEngine;
use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Engines\Review\ReviewEngine;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\ReviewReport;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TosNotification;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Tos\DiscoveryCandidateRepository;
use App\Repositories\Tos\EvaluationResultRepository;
use App\Repositories\Tos\ExecutionQueryRepository;
use App\Repositories\Tos\MarketDataRepository;
use App\Repositories\Tos\NotificationQueryRepository;
use App\Repositories\Tos\RecommendationQueryRepository;
use App\Repositories\Tos\ReviewReportRepository;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TosRepositoryQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
        ]);
    }

    public function test_market_data_filters_sorts_paginates_and_excludes_benchmarks(): void
    {
        $repo = app(MarketDataRepository::class);
        foreach (['ZZZ', 'AAA', 'MMM'] as $symbol) {
            $this->makeStock($symbol);
        }
        $this->makeStock('BNCH', benchmark: true);
        $inactive = $this->makeStock('INAC');
        $inactive->forceFill(['is_active' => false])->save();

        $page = $repo->paginateSecurities('A', 2, 1);
        $this->assertSame(1, $page->currentPage());
        $this->assertSame(2, $page->perPage());
        $this->assertSame(2, $page->total());
        $this->assertSame(['AAA', 'INAC'], $page->pluck('symbol')->all());

        $all = $repo->paginateSecurities(null, 50, 1);
        $this->assertSame(['AAA', 'INAC', 'MMM', 'ZZZ'], $all->pluck('symbol')->all());
        $this->assertNull($repo->findSecurity(999999));

        $aaa = Stock::query()->where('symbol', 'AAA')->firstOrFail();
        foreach (['2026-08-01', '2026-08-10', '2026-08-20'] as $i => $date) {
            $this->makeBar($aaa->id, $date, 10 + $i);
        }
        $this->makeBar($aaa->id, '2026-07-01', 1);

        $bars = $repo->paginatePriceBars($aaa->id, '2026-08-01', '2026-08-15', 1, 1);
        $this->assertSame(2, $bars->total());
        $this->assertSame(1, $bars->perPage());
        $this->assertSame('2026-08-10', $bars->items()[0]->price_date->toDateString());

        $clamped = $repo->paginatePriceBars($aaa->id, null, null, 999, 1);
        $this->assertSame(500, $clamped->perPage());

        $counts = $repo->inspectionCounts();
        $this->assertSame(3, $counts['securities_active']);
        $this->assertSame(4, $counts['price_bars']);
        $this->assertNotNull($counts['latest_price_date']);
    }

    public function test_discovery_candidates_scope_to_latest_completed_and_join_security(): void
    {
        [$profile, $other] = $this->twoProfiles();
        $alpha = $this->makeStock('ALFA');
        $beta = $this->makeStock('BETA');
        $old = $this->makeDiscovery($profile->id, 'completed');
        $latest = $this->makeDiscovery($profile->id, 'completed');
        $running = $this->makeDiscovery($profile->id, 'running');
        $otherRun = $this->makeDiscovery($other->id, 'completed');

        $this->makeCandidate($old->id, $alpha->id, 'watchlist');
        $cLatestA = $this->makeCandidate($latest->id, $alpha->id, 'pattern');
        $cLatestB = $this->makeCandidate($latest->id, $beta->id, 'watchlist');
        $this->makeCandidate($running->id, $alpha->id, 'pattern');
        $this->makeCandidate($otherRun->id, $alpha->id, 'pattern');

        $repo = app(DiscoveryCandidateRepository::class);
        $this->assertSame($latest->id, $repo->latestCompletedId($profile));

        $forProfile = $repo->listFiltered(null, $profile);
        $this->assertEqualsCanonicalizing([$cLatestA->id, $cLatestB->id], $forProfile->pluck('id')->all());
        $this->assertTrue($forProfile->first()->relationLoaded('security'));

        $patterns = $repo->listFiltered(null, $profile, 'pattern');
        $this->assertSame([$cLatestA->id], $patterns->pluck('id')->all());

        $search = $repo->listFiltered(null, $profile, null, 'bet');
        $this->assertSame([$cLatestB->id], $search->pluck('id')->all());

        $emptyProfile = $this->defaultPortfolioFor(User::factory()->create());
        $this->assertCount(0, $repo->listFiltered(null, $emptyProfile));
    }

    public function test_evaluation_results_use_latest_completed_run_and_rank_order(): void
    {
        [$profile, $other] = $this->twoProfiles();
        $stock = $this->makeStock('EVL1');
        $discovery = $this->makeDiscovery($profile->id, 'completed');
        $older = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $discovery->id,
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);
        $latest = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $discovery->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $otherRun = EvaluationRun::query()->create([
            'profile_id' => $other->id,
            'discovery_run_id' => $this->makeDiscovery($other->id, 'completed')->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $c1 = $this->makeCandidate($discovery->id, $stock->id, 'watchlist');
        $c2 = $this->makeCandidate($discovery->id, $this->makeStock('EVL2')->id, 'watchlist');
        $this->makeEvalResult($older->id, $c1->id, 1);
        $r2 = $this->makeEvalResult($latest->id, $c2->id, 2);
        $r1 = $this->makeEvalResult($latest->id, $c1->id, 1);
        $this->makeEvalResult($otherRun->id, $this->makeCandidate(
            DiscoveryRun::query()->where('profile_id', $other->id)->value('id'),
            $stock->id,
            'watchlist',
        )->id, 1);

        $repo = app(EvaluationResultRepository::class);
        $listed = $repo->listResults(null, $profile);
        $this->assertSame([$r1->id, $r2->id], array_map(fn ($row) => $row->id, $listed));
        $this->assertTrue($listed[0]->relationLoaded('candidate'));
        $this->assertSame([], $repo->listResults($otherRun->id, $profile));
        $this->assertSame(
            [$latest->id, $older->id],
            array_map(fn ($row) => $row->id, $repo->listRuns($profile)),
        );
        $this->assertSame([], $repo->listResults(null, $this->defaultPortfolioFor(User::factory()->create())));
    }

    public function test_recommendation_queries_scope_filter_paginate_and_not_found(): void
    {
        [$profile, $other] = $this->twoProfiles();
        $stock = $this->makeStock('REC1');
        $high = $this->makeRec($profile->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 90);
        $low = $this->makeRec($profile->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 10);
        $rejected = $this->makeRec($profile->id, $stock->id, TradingRecommendation::STATUS_REJECTED, 80);
        $this->makeRec($other->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 99);

        $repo = app(RecommendationQueryRepository::class);
        $page = $repo->paginateForProfile($profile, [TradingRecommendation::STATUS_PENDING_REVIEW], 1, 1);
        $this->assertSame(2, $page->total());
        $this->assertSame(1, $page->perPage());
        $this->assertSame($high->id, $page->items()[0]->id);
        $this->assertSame(2, $page->lastPage());

        $page2 = $repo->paginateForProfile($profile, [TradingRecommendation::STATUS_PENDING_REVIEW], 2, 1);
        $this->assertSame($low->id, $page2->items()[0]->id);

        $rejectedList = $repo->listForProfile($profile, [TradingRecommendation::STATUS_REJECTED]);
        $this->assertSame([$rejected->id], array_map(fn ($r) => $r->id, $rejectedList));

        $this->assertNull($repo->findForProfile($profile, $this->makeRec($other->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 1)->id));
        $this->assertNotNull($repo->findForProfile($profile, $high->id));
        $this->assertNull($repo->findForProfile($profile, 999999));
    }

    public function test_notification_execution_review_queries_scope_and_paginate(): void
    {
        [$profile, $other] = $this->twoProfiles();
        $stock = $this->makeStock('XEQ1');

        $n1 = TosNotification::query()->create([
            'profile_id' => $profile->id,
            'notification_type' => 'recommendation',
            'channel' => 'telegram',
            'status' => 'failed',
            'idempotency_key' => 'n-'.$profile->id.'-a',
        ]);
        TosNotification::query()->create([
            'profile_id' => $profile->id,
            'notification_type' => 'recommendation',
            'channel' => 'telegram',
            'status' => 'delivered',
            'idempotency_key' => 'n-'.$profile->id.'-b',
        ]);
        TosNotification::query()->create([
            'profile_id' => $other->id,
            'notification_type' => 'recommendation',
            'channel' => 'telegram',
            'status' => 'failed',
            'idempotency_key' => 'n-'.$other->id,
        ]);

        $notes = app(NotificationQueryRepository::class);
        $page = $notes->paginateHistory($profile, 1, 1);
        $this->assertSame(2, $page->total());
        $this->assertSame(1, $page->perPage());
        $this->assertNull($notes->findForProfile($profile, 999999));
        $this->assertSame($n1->id, $notes->findByIdempotencyKey('n-'.$profile->id.'-a')?->id);
        $this->assertNull($notes->findForProfile($other, $n1->id));

        TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 1,
            'status' => TradingOrder::STATUS_PENDING,
        ]);
        $cancelled = TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 2,
            'status' => TradingOrder::STATUS_CANCELLED,
        ]);
        TradingOrder::query()->create([
            'profile_id' => $other->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 3,
            'status' => TradingOrder::STATUS_PENDING,
        ]);

        $exec = app(ExecutionQueryRepository::class);
        $pending = $exec->paginateOrders($profile, 1, 50, TradingOrder::STATUS_PENDING);
        $this->assertSame(1, $pending->total());
        $allOrders = $exec->paginateOrders($profile, 1, 50);
        $this->assertSame(2, $allOrders->total());
        $this->assertNull($exec->findOrder($other, $cancelled->id));
        $this->assertNotNull($exec->findOrder($profile, $cancelled->id));

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 4,
            'avg_buy_price' => 10,
            'invested_amount' => 40,
            'updated_at' => now(),
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $this->makeStock('FLAT')->id,
            'quantity' => 0,
            'avg_buy_price' => 10,
            'invested_amount' => 0,
            'updated_at' => now(),
        ]);
        $this->assertCount(1, $exec->listOpenPositions($profile));

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 10,
            'fees' => 0,
            'transaction_date' => '2026-08-20',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 11,
            'fees' => 0,
            'transaction_date' => '2026-08-21',
        ]);
        $tx = $exec->paginateTransactions($profile, 1, 1);
        $this->assertSame(2, $tx->total());
        $this->assertSame('2026-08-21', $tx->items()[0]->transaction_date->toDateString());

        $r1 = ReviewReport::query()->create([
            'profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'completed',
            'generated_at' => now()->subDay(),
        ]);
        ReviewReport::query()->create([
            'profile_id' => $profile->id,
            'period_start' => now()->subDays(3)->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'completed',
            'generated_at' => now(),
        ]);
        ReviewReport::query()->create([
            'profile_id' => $other->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $reviews = app(ReviewReportRepository::class);
        $rp = $reviews->paginateReports($profile, 1, 1);
        $this->assertSame(2, $rp->total());
        $this->assertSame(1, $rp->perPage());
        $this->assertNull($reviews->findForProfile($other, $r1->id));
        $this->assertNotNull($reviews->findForProfile($profile, $r1->id));
    }

    public function test_engines_delegate_list_find_pagination_and_keep_expire_stale(): void
    {
        [$profile, $other] = $this->twoProfiles();
        $stock = $this->makeStock('ENG1');
        $this->makeStock('ENG2');

        $data = app(DataEngine::class);
        $securities = $data->listSecurities('ENG', 1, 1);
        $this->assertSame(2, $securities->total());
        $this->assertSame(1, $securities->perPage());
        $this->assertNull($data->securityDetails(999999));

        $run = $this->makeDiscovery($profile->id, 'completed');
        $cLow = $this->makeCandidate($run->id, $stock->id, 'watchlist');
        $cHigh = $this->makeCandidate($run->id, Stock::query()->where('symbol', 'ENG2')->value('id'), 'pattern');
        $evalRun = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $run->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $this->makeEvalResult($evalRun->id, $cHigh->id, 1);
        $this->makeEvalResult($evalRun->id, $cLow->id, 2);

        $candidates = app(DiscoveryEngine::class)->listCandidates(null, $profile);
        $this->assertSame([$cHigh->id, $cLow->id], array_map(fn ($c) => $c->id, $candidates));

        $results = app(EvaluationEngine::class)->listResults(null, $profile);
        $this->assertSame([1, 2], array_map(fn ($r) => (int) $r->rank, $results));

        $stale = $this->makeRec($profile->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 50);
        $stale->forceFill(['expires_at' => now()->subMinute()])->save();
        $fresh = $this->makeRec($profile->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 40);
        $this->makeRec($other->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW, 99);

        $life = app(RecommendationLifecycleService::class);
        $page = $life->paginateForProfile($profile, [TradingRecommendation::STATUS_PENDING_REVIEW], 1, 50);
        $this->assertSame(1, $page->total());
        $this->assertSame($fresh->id, $page->items()[0]->id);
        $this->assertSame(TradingRecommendation::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertNull($life->findForProfile($profile, 999999));

        $note = TosNotification::query()->create([
            'profile_id' => $profile->id,
            'notification_type' => 'recommendation',
            'channel' => 'telegram',
            'status' => 'failed',
            'idempotency_key' => 'eng-n-'.Str::random(6),
        ]);
        $this->assertNull(app(NotificationEngine::class)->retry($other, $note->id));
        $history = app(NotificationEngine::class)->paginateHistory($profile, 1, 50);
        $this->assertSame(1, $history->total());

        $order = TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 1,
            'status' => TradingOrder::STATUS_PENDING,
        ]);
        $this->assertNull(app(ExecutionEngine::class)->findOrder($other, $order->id));
        $orders = app(ExecutionEngine::class)->paginateOrders($profile, 1, 50);
        $this->assertSame(1, $orders->total());

        $report = ReviewReport::query()->create([
            'profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'completed',
            'generated_at' => now(),
        ]);
        $this->assertNull(app(ReviewEngine::class)->findReport($other, $report->id));
        $this->assertSame(1, app(ReviewEngine::class)->paginateReports($profile, 1, 20)->total());
    }

    /**
     * @return array{0: \App\Models\PortfolioProfile, 1: \App\Models\PortfolioProfile}
     */
    protected function twoProfiles(): array
    {
        $a = $this->defaultPortfolioFor(User::factory()->create());
        $b = $this->defaultPortfolioFor(User::factory()->create());

        return [$a, $b];
    }

    protected function makeStock(string $symbol, bool $benchmark = false): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol.' Co',
            'is_active' => true,
            'is_benchmark' => $benchmark,
        ]);
    }

    protected function makeBar(int $stockId, string $date, float $close): StockPrice
    {
        return StockPrice::query()->create([
            'stock_id' => $stockId,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 1000,
            'data_source' => 'test',
        ]);
    }

    protected function makeDiscovery(int $profileId, string $status): DiscoveryRun
    {
        return DiscoveryRun::query()->create([
            'profile_id' => $profileId,
            'dataset_version' => 'ds-test',
            'status' => $status,
            'started_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            'stats_json' => [],
        ]);
    }

    protected function makeCandidate(int $runId, int $securityId, string $source): Candidate
    {
        return Candidate::query()->create([
            'discovery_run_id' => $runId,
            'security_id' => $securityId,
            'source' => $source,
            'evidence' => [],
            'created_at' => now(),
        ]);
    }

    protected function makeEvalResult(int $runId, int $candidateId, int $rank): EvaluationResult
    {
        return EvaluationResult::query()->create([
            'evaluation_run_id' => $runId,
            'candidate_id' => $candidateId,
            'score' => 50,
            'confidence' => 0.5,
            'rank' => $rank,
            'evidence' => [],
            'passed_rules' => [],
            'failed_rules' => [],
            'created_at' => now(),
        ]);
    }

    protected function makeRec(int $profileId, int $stockId, string $status, int $priority): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profileId,
            'security_id' => $stockId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'priority' => $priority,
            'confidence' => 0.5,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }
}
