<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyticsService;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\CashManagementService;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiPureReadReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_updates_only_selected_active_legacy_strategy(): void
    {
        $profile = $this->defaultPortfolioFor(User::factory()->create());
        $first = app(\App\Services\StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = \App\Models\TradingStrategy::query()->create(['profile_id' => $profile->id, 'name' => 'Strategy B', 'slug' => 'strategy_b', 'status' => 'active', 'is_factory' => false]);
        $version = $this->createTestStrategyVersion(['strategy_id' => $second->id, 'version' => 1, 'version_label' => '1.0', 'config_json' => $first->activeVersion->config_json, 'status' => 'active']);
        $second->forceFill(['active_version_id' => $version->id])->save();
        $registry = app(StrategyArtifactRegistry::class);
        $envelope = $registry->get((string) $second->id, $profile);
        $before = $first->fresh()->getAttributes();
        $beforeVersion = $first->activeVersion->fresh()->getAttributes();
        $envelope['name'] = 'Updated Strategy B';
        $registry->update((string) $second->id, $envelope, $profile);
        self::assertSame('Updated Strategy B', $second->fresh()->name);
        self::assertSame($before, $first->fresh()->getAttributes());
        self::assertSame($beforeVersion, $first->activeVersion->fresh()->getAttributes());
        self::assertSame('active', $second->fresh()->status);
    }

    public function test_missing_state_reads_never_initialize_business_tables(): void
    {
        $profile = PortfolioProfile::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Uninitialized', 'portfolio_type' => 'live']);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        self::assertSame('not_initialized', app(WatchlistService::class)->readWatchlistsForProfile($profile)['availability']);
        self::assertSame('not_initialized', app(StrategyArtifactRegistry::class)->readForProfile($profile)['availability']);
        self::assertSame('not_initialized', app(CashManagementService::class)->readSummary($profile)['availability']);
        $account = app(CashManagementService::class)->readAccountSummary($profile->user);
        self::assertSame('incomplete', $account['availability']);
        self::assertNull($account['data']['cash_balance']);
        self::assertSame('not_initialized', app(PortfolioAnalyticsService::class)->readForProfile($profile)['availability']);
        self::assertSame([], $writes);
    }

    public function test_missing_holding_price_is_incomplete_not_a_measured_zero(): void
    {
        $profile = $this->defaultPortfolioFor(User::factory()->create());
        CashAccount::query()->firstOrCreate(['profile_id' => $profile->id], ['balance' => 100]);
        $stock = \App\Models\Stock::query()->create(['symbol' => 'NOQUOTE', 'name' => 'Missing quote', 'exchange' => 'NSE', 'series' => 'EQ', 'is_active' => true]);
        \App\Models\Holding::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'quantity' => 2, 'avg_buy_price' => 10, 'invested_amount' => 20]);
        $projection = app(PortfolioAnalyticsService::class)->readForProfile($profile);
        self::assertSame('incomplete', $projection['availability']);
        self::assertNull($projection['data']);
    }

    public function test_initialized_projection_has_no_writes_or_placeholder_evidence(): void
    {
        $profile = $this->defaultPortfolioFor(User::factory()->create());
        CashAccount::query()->firstOrCreate(['profile_id' => $profile->id], ['balance' => 100]);
        $foreign = $this->defaultPortfolioFor(User::factory()->create());
        CashAccount::query()->firstOrCreate(['profile_id' => $foreign->id], ['balance' => 999]);
        app(WatchlistService::class)->ensureDefaultWatchlist($profile);
        app(\App\Services\StrategyConfigurationService::class)->ensureActive($profile);
        $stock = \App\Models\Stock::query()->create(['symbol' => 'PURE', 'name' => 'Pure read fixture', 'exchange' => 'NSE', 'series' => 'EQ', 'is_active' => true]);
        \App\Models\Holding::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'quantity' => 2, 'avg_buy_price' => 10, 'invested_amount' => 20]);
        \App\Models\StockPrice::query()->create(['stock_id' => $stock->id, 'price_date' => now()->toDateString(), 'close_price' => 15, 'open_price' => 15, 'high_price' => 15, 'low_price' => 15, 'volume' => 100, 'data_source' => 'test']);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        self::assertSame('available', app(WatchlistService::class)->readWatchlistsForProfile($profile)['availability']);
        self::assertSame('available', app(StrategyArtifactRegistry::class)->readForProfile($profile)['availability']);
        $account = app(CashManagementService::class)->readAccountSummary($profile->user);
        self::assertSame('available', $account['availability']);
        self::assertCount(1, $account['data']['profiles']);
        self::assertEqualsWithDelta(100, $account['data']['cash_balance'], .0001);
        $analytics = app(PortfolioAnalyticsService::class)->readForProfile($profile);
        self::assertSame('available', $analytics['availability']);
        self::assertEqualsWithDelta(30, $analytics['data']['summary']['portfolio_value'], .0001);
        self::assertEqualsWithDelta(100, $analytics['data']['largest_position_pct'], .0001);
        foreach (['sector_allocation', 'portfolio_beta', 'portfolio_correlation'] as $field) {
            self::assertNotSame('available', $analytics['data'][$field]['availability']);
            self::assertNull($analytics['data'][$field]['value']);
        }
        self::assertSame([], $writes);
    }
}