<?php

namespace Tests\Feature;

use App\Models\ReviewReport;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TosNotification;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingOsPaginationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
        ]);
    }

    public function test_guest_cannot_list_paginated_endpoints(): void
    {
        $this->getJson('/api/v1/securities')->assertUnauthorized();
        $this->getJson('/api/v1/recommendations')->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_securities_default_page_explicit_page_max_and_search(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();
        foreach (['PGAA', 'PGAB', 'PGAC'] as $symbol) {
            Stock::query()->create([
                'symbol' => $symbol,
                'exchange' => 'NSE',
                'name' => $symbol.' Co',
                'is_active' => true,
                'is_benchmark' => false,
            ]);
        }
        Stock::query()->create([
            'symbol' => 'OTHER',
            'exchange' => 'NSE',
            'name' => 'Unrelated',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile);

        $default = $this->getJson('/api/v1/securities?search=PGA');
        $default->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 50)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.lastPage', 1);
        $this->assertCount(3, $default->json('data'));

        $page1 = $this->getJson('/api/v1/securities?search=PGA&page=1&pageSize=2');
        $page1->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.lastPage', 2);
        $this->assertCount(2, $page1->json('data'));

        $page2 = $this->getJson('/api/v1/securities?search=PGA&page=2&per_page=2');
        $page2->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.pageSize', 2)
            ->assertJsonPath('meta.total', 3);
        $this->assertCount(1, $page2->json('data'));

        $emptyPage = $this->getJson('/api/v1/securities?search=PGA&page=9&pageSize=2');
        $emptyPage->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.page', 9)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.lastPage', 2);

        $clamped = $this->getJson('/api/v1/securities?search=PGA&pageSize=999');
        $clamped->assertOk()->assertJsonPath('meta.pageSize', 200);
        $this->assertCount(3, $clamped->json('data'));
    }

    public function test_price_bars_are_database_paginated_with_500_max(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();
        $stock = Stock::query()->create([
            'symbol' => 'PBAR',
            'exchange' => 'NSE',
            'name' => 'Price Bar',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        for ($i = 0; $i < 5; $i++) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($i)->toDateString(),
                'open_price' => 10,
                'high_price' => 11,
                'low_price' => 9,
                'close_price' => 10,
                'volume' => 1000,
                'data_source' => 'test',
            ]);
        }

        $this->actingAs($user)->withProfileHeader($user, $profile);

        $page = $this->getJson('/api/v1/price-bars?security_id='.$stock->id.'&page=2&pageSize=2');
        $page->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.pageSize', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.lastPage', 3);
        $this->assertCount(2, $page->json('data'));

        $this->getJson('/api/v1/price-bars?security_id='.$stock->id.'&pageSize=999')
            ->assertOk()
            ->assertJsonPath('meta.pageSize', 500);
    }

    public function test_recommendations_pagination_filters_and_portfolio_scope(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);
        $stock = Stock::query()->create([
            'symbol' => 'RPG1',
            'exchange' => 'NSE',
            'name' => 'Rec Paginate',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $openIds = [];
        for ($i = 0; $i < 3; $i++) {
            $openIds[] = $this->makeRecommendation($profile->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW)->id;
        }
        $this->makeRecommendation($profile->id, $stock->id, TradingRecommendation::STATUS_REJECTED);
        $this->makeRecommendation($otherProfile->id, $stock->id, TradingRecommendation::STATUS_PENDING_REVIEW);

        $this->actingAs($user)->withProfileHeader($user, $profile);

        $default = $this->getJson('/api/v1/recommendations');
        $default->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 100)
            ->assertJsonPath('meta.total', 3);
        $this->assertEqualsCanonicalizing($openIds, array_map('intval', $default->json('data.*.id')));

        $page1 = $this->getJson('/api/v1/recommendations?page=1&pageSize=2');
        $page1->assertOk()
            ->assertJsonPath('meta.pageSize', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.lastPage', 2);
        $this->assertCount(2, $page1->json('data'));

        $rejected = $this->getJson('/api/v1/recommendations?status=rejected&pageSize=50');
        $rejected->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(TradingRecommendation::STATUS_REJECTED, $rejected->json('data.0.status'));

        $this->actingAs($other)->withProfileHeader($other, $otherProfile)
            ->getJson('/api/v1/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_orders_notifications_transactions_reviews_share_meta_shape(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();
        $stock = Stock::query()->create([
            'symbol' => 'ORD1',
            'exchange' => 'NSE',
            'name' => 'Order Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 1,
            'status' => TradingOrder::STATUS_PENDING,
        ]);
        TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 2,
            'status' => TradingOrder::STATUS_CANCELLED,
        ]);
        TosNotification::query()->create([
            'profile_id' => $profile->id,
            'notification_type' => 'recommendation',
            'channel' => 'telegram',
            'status' => 'failed',
            'idempotency_key' => 'page-n-'.Str::random(8),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 10,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);
        ReviewReport::query()->create([
            'profile_id' => $profile->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'completed',
            'generated_at' => now(),
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile);

        $orders = $this->getJson('/api/v1/orders?status=pending&pageSize=1');
        $orders->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.lastPage', 1);
        $this->assertSame(TradingOrder::STATUS_PENDING, $orders->json('data.0.status'));

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 50)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('meta.pageSize', 100)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonPath('meta.pageSize', 20)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/positions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta', []);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile}
     */
    protected function actingPortfolioUser(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        return [$user, $profile];
    }

    protected function makeRecommendation(int $profileId, int $stockId, string $status): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profileId,
            'security_id' => $stockId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'priority' => 50,
            'confidence' => 0.5,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }
}
