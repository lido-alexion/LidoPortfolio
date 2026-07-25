<?php

namespace Tests\Feature;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingOsPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trading_os.enabled' => true,
            'trading_os.evaluation.min_bars' => 15,
            'trading_os.notification.notify_on_generate' => false,
            'trading_os.discovery.include_screener_hits' => false,
            'trading_os.discovery.include_patterns' => true,
        ]);
    }

    public function test_pipeline_produces_ranked_recommendations(): void
    {
        [$user, $profile, $stock] = $this->seedWatchlistWithTrend();

        $result = app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => true,
        ]);

        $this->assertSame('completed', $result['pipeline_run']->status);
        $this->assertGreaterThanOrEqual(1, $result['stages']['discovery']['candidates']);
        $this->assertGreaterThanOrEqual(1, $result['stages']['evaluation']['results']);
        $this->assertGreaterThanOrEqual(1, $result['stages']['recommendation']['count']);
        $this->assertNotEmpty($result['stages']['review']['report_id'] ?? null);

        $this->assertDatabaseHas('portfolio_tos_recommendations', [
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
        ]);

        $rec = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('security_id', $stock->id)
            ->first();
        $this->assertNotNull($rec);
        $type = strtoupper((string) $rec->recommendation_type);
        if (in_array($type, ['HOLD_POSITION', 'HOLD', 'WATCH'], true)) {
            $this->assertSame('published', $rec->status);
            $this->assertFalse($rec->canBeReviewed());
            $this->assertFalse($rec->canCreateOrder());
        } else {
            $this->assertSame('pending_review', $rec->status);
            $this->assertTrue($rec->canBeReviewed());
            $this->assertNotEmpty($rec->market_opinion);
        }
    }

    public function test_v1_recommendations_api_and_order_execution(): void
    {
        [$user, $profile, $stock] = $this->seedWatchlistWithTrend();

        app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => false,
        ]);

        $this->actingAs($user);
        $this->withProfileHeader($user, $profile);

        $list = $this->getJson('/api/v1/recommendations');
        $list->assertOk();
        $list->assertJsonPath('success', true);
        $this->assertNotEmpty($list->json('data'));

        $recId = $list->json('data.0.id');
        // Order lifecycle applies to BUY/SELL only (WATCH/HOLD cannot create orders).
        TradingRecommendation::query()->whereKey($recId)->update([
            'recommendation_type' => 'OPEN_POSITION',
            'status' => 'pending_review',
        ]);

        $detail = $this->getJson('/api/v1/recommendations/'.$recId);
        $detail->assertOk();
        $detail->assertJsonPath('data.id', $recId);
        $this->assertArrayHasKey('evidence', $detail->json('data'));

        // Must accept before creating an order.
        $blocked = $this->postJson('/api/v1/orders', [
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 2,
            'price' => 120,
            'fees' => 1,
            'recommendation_id' => $recId,
            'transaction_date' => now()->toDateString(),
        ]);
        $blocked->assertStatus(422);

        $accept = $this->postJson('/api/v1/recommendations/'.$recId.'/review', [
            'decision' => 'accepted',
            'notes' => 'Looks good',
        ]);
        $accept->assertOk();
        $accept->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('portfolio_tos_recommendation_reviews', [
            'recommendation_id' => $recId,
            'decision' => 'accepted',
            'user_id' => $user->id,
        ]);

        $pending = $this->postJson('/api/v1/orders', [
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 2,
            'recommendation_id' => $recId,
            'execute_now' => false,
        ]);
        $pending->assertCreated();
        $pending->assertJsonPath('data.order.status', 'pending');
        $orderId = $pending->json('data.order.id');

        $order = $this->postJson('/api/v1/orders/'.$orderId.'/execute', [
            'price' => 120,
            'fees' => 1,
            'transaction_date' => now()->toDateString(),
        ]);
        $order->assertOk();
        $order->assertJsonPath('success', true);
        $order->assertJsonPath('data.order.status', 'executed');

        $this->assertDatabaseHas('portfolio_tos_recommendations', [
            'id' => $recId,
            'status' => 'executed',
        ]);
        $this->assertDatabaseHas('portfolio_transactions', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
        ]);
        $this->assertDatabaseHas('portfolio_holdings', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
        ]);

        $positions = $this->getJson('/api/v1/positions');
        $positions->assertOk();
        $this->assertTrue(collect($positions->json('data'))->contains(fn ($p) => (int) $p['security_id'] === $stock->id));

        $dashboard = $this->getJson('/api/v1/review/dashboard');
        $dashboard->assertOk();
        $dashboard->assertJsonPath('success', true);
        $this->assertNotEmpty($dashboard->json('data.outcomes'));

        // Undo executed fill: delete ledger tx → recommendation reopens.
        $txId = \App\Models\Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->orderByDesc('id')
            ->value('id');
        $this->assertNotNull($txId);
        $del = $this->deleteJson('/api/transactions/'.$txId);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('tos.recommendation_reopened'));
        $this->assertDatabaseHas('portfolio_tos_recommendations', [
            'id' => $recId,
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('portfolio_tos_orders', [
            'id' => $orderId,
            'status' => 'cancelled',
        ]);

        // Reject then reopen.
        $this->postJson('/api/v1/recommendations/'.$recId.'/review', ['decision' => 'rejected'])->assertOk();
        $this->assertDatabaseHas('portfolio_tos_recommendations', ['id' => $recId, 'status' => 'rejected']);
        $reopen = $this->postJson('/api/v1/recommendations/'.$recId.'/reopen', ['notes' => 'Undo reject']);
        $reopen->assertOk();
        $reopen->assertJsonPath('data.status', 'pending_review');
        $this->assertDatabaseHas('portfolio_tos_recommendation_reviews', [
            'recommendation_id' => $recId,
            'decision' => 'reopened',
        ]);
    }

    public function test_order_cancel_lifecycle(): void
    {
        [$user, $profile, $stock] = $this->seedWatchlistWithTrend();
        app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);

        $this->actingAs($user);
        $this->withProfileHeader($user, $profile);

        $recId = $this->getJson('/api/v1/recommendations')->json('data.0.id');
        TradingRecommendation::query()->whereKey($recId)->update([
            'recommendation_type' => 'OPEN_POSITION',
            'status' => 'pending_review',
        ]);
        $this->postJson('/api/v1/recommendations/'.$recId.'/review', ['decision' => 'accepted'])->assertOk();

        $pending = $this->postJson('/api/v1/orders', [
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 1,
            'recommendation_id' => $recId,
            'execute_now' => false,
        ]);
        $pending->assertCreated();
        $orderId = $pending->json('data.order.id');

        $this->postJson('/api/v1/orders/'.$orderId.'/cancel')->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('portfolio_tos_orders', [
            'id' => $orderId,
            'status' => 'cancelled',
        ]);
    }

    public function test_evaluation_scores_are_deterministic(): void
    {
        [$user, $profile, $stock] = $this->seedWatchlistWithTrend();

        $pipeline = app(DailyDecisionPipeline::class);
        $a = $pipeline->run($profile, ['notify' => false, 'review' => false]);
        $scoreA = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('security_id', $stock->id)
            ->orderByDesc('id')
            ->value('confidence');

        // Cancel and regenerate via second pipeline run.
        $b = $pipeline->run($profile, ['notify' => false, 'review' => false]);
        $scoreB = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('security_id', $stock->id)
            ->orderByDesc('id')
            ->value('confidence');

        $this->assertNotNull($scoreA);
        $this->assertSame((string) $scoreA, (string) $scoreB);
        $this->assertSame('completed', $a['pipeline_run']->status);
        $this->assertSame('completed', $b['pipeline_run']->status);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock}
     */
    protected function seedWatchlistWithTrend(): array
    {
        $user = User::query()->create([
            'name' => 'TOS User',
            'email' => 'tos-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $stock = Stock::query()->create([
            'symbol' => 'T'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'TOS Trend Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'updated_at' => now(),
        ]);

        $this->seedUptrendWithHammer($stock);

        return [$user, $profile, $stock];
    }

    protected function seedUptrendWithHammer(Stock $stock): void
    {
        // Enough history for evaluation indicators, then a known hammer setup.
        $start = 80.0;
        for ($i = 0; $i < 40; $i++) {
            $close = $start + ($i * 0.5);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays(50 - $i)->toDateString(),
                'open_price' => $close - 0.3,
                'high_price' => $close + 0.6,
                'low_price' => $close - 0.6,
                'close_price' => $close,
                'volume' => 50000 + ($i * 500),
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $base = 120.0;
        for ($i = 0; $i < 8; $i++) {
            $close = $base - ($i * 2);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays(9 - $i)->toDateString(),
                'open_price' => $close + 1,
                'high_price' => $close + 2,
                'low_price' => $close - 2,
                'close_price' => $close,
                'volume' => 1000,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDay()->toDateString(),
            'open_price' => 102,
            'high_price' => 103,
            'low_price' => 94,
            'close_price' => 103,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }
}
