<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\CashManagementService;
use App\Services\Strategy\HoldingOwnershipBackfill;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Workstream 1 — domain identity / multi-strategy foundation.
 */
class V3DomainIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_can_have_multiple_enabled_strategies(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Second Strategy');

        app(StrategyRegistrySupport::class)->activate($profile, $second);

        $this->assertSame(
            2,
            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->count()
        );
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $first->fresh()->status);
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $second->fresh()->status);
    }

    public function test_strategies_remain_associated_with_the_correct_portfolio(): void
    {
        $user = User::factory()->create();
        $a = $this->createPortfolioProfile($user, 'Alpha', true);
        $b = $this->createPortfolioProfile($user, 'Beta', false);

        $strategyA = app(StrategyConfigurationService::class)->ensureActive($a)->strategy;
        $strategyB = $this->makeStrategy($b, 'Beta Strategy');
        app(StrategyRegistrySupport::class)->activate($b, $strategyB);

        $this->assertSame((int) $a->id, (int) $strategyA->profile_id);
        $this->assertSame((int) $b->id, (int) $strategyB->profile_id);
        $this->assertSame(1, $a->strategies()->count());
        $this->assertTrue($b->strategies()->where('id', $strategyB->id)->exists());
        $this->assertFalse(
            TradingStrategy::query()->where('profile_id', $a->id)->where('id', $strategyB->id)->exists()
        );
    }

    public function test_existing_single_strategy_portfolio_remains_valid(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $version = app(StrategyConfigurationService::class)->ensureActive($profile);
        $strategy = $version->strategy;

        $this->assertSame(1, TradingStrategy::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $strategy->status);
        $this->assertEqualsWithDelta(100.0, (float) $strategy->allocation_pct, 0.0001);

        $this->actingAs($user)
            ->getJson('/api/v1/strategy')
            ->assertOk()
            ->assertJsonPath('data.id', $strategy->id)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_recommendation_strategy_identity_is_preserved_when_another_strategy_is_enabled(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $firstVersion = app(StrategyConfigurationService::class)->ensureActive($profile);
        $stock = $this->makeStock();

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $firstVersion->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);

        $second = $this->makeStrategy($profile, 'Other Strategy');
        app(StrategyRegistrySupport::class)->activate($profile, $second);

        $rec->refresh();
        $this->assertSame((int) $firstVersion->id, (int) $rec->strategy_version_id);
        $this->assertSame((int) $firstVersion->strategy_id, (int) $rec->owningStrategyId());
        $this->assertNotSame((int) $second->id, (int) $rec->owningStrategyId());
    }

    public function test_holdings_owner_is_inferred_for_single_strategy_and_qty_is_unchanged(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);
        $stock = $this->makeStock();

        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 12.5,
            'avg_buy_price' => 100,
            'invested_amount' => 1250,
            'total_fees' => 1.25,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        $this->assertTrue($holding->isUnmanaged());

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);
        $this->assertSame(1, $updated);

        $holding->refresh();
        $this->assertSame((int) $version->strategy_id, (int) $holding->strategy_id);
        $this->assertSame(Holding::ownerKeyFor((int) $version->strategy_id), $holding->owner_key);
        $this->assertFalse($holding->isUnmanaged());
        $this->assertEqualsWithDelta(12.5, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(1250.0, (float) $holding->invested_amount, 0.0001);
    }

    public function test_schema_allows_two_owners_of_the_same_stock(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Owner B');
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        $stock = $this->makeStock();

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $first->id,
            'owner_key' => Holding::ownerKeyFor((int) $first->id),
            'quantity' => 10,
            'avg_buy_price' => 50,
            'invested_amount' => 500,
            'updated_at' => now(),
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $second->id,
            'owner_key' => Holding::ownerKeyFor((int) $second->id),
            'quantity' => 7,
            'avg_buy_price' => 60,
            'invested_amount' => 420,
            'updated_at' => now(),
        ]);

        $this->assertSame(
            2,
            Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->count()
        );
    }

    public function test_exclusive_active_restriction_is_not_enforced(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(StrategyConfigurationService::class)->ensureActive($profile);
        $second = $this->makeStrategy($profile, 'Coexist');

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$second->id.'/activate')
            ->assertOk();

        $meta = $this->actingAs($user)
            ->getJson('/api/v1/strategy-registry/meta')
            ->assertOk()
            ->json('data');
        $this->assertSame(StrategyArtifactRegistry::ENABLEMENT_RULE, $meta['selection_rule'] ?? null);
        $this->assertNotSame('exactly_one_active_per_portfolio', $meta['selection_rule'] ?? null);

        $this->assertSame(
            2,
            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->count()
        );
    }

    public function test_physical_cash_is_unchanged_when_enabling_a_second_strategy(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(StrategyConfigurationService::class)->ensureActive($profile);
        app(CashManagementService::class)->deposit($profile, 50_000, 'seed', $user);

        $cashBefore = app(CashManagementService::class)->summary($profile);
        $ledgerCount = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();

        $this->assertFalse(Schema::hasColumn('portfolio_cash_accounts', 'strategy_id'));
        $this->assertFalse(Schema::hasColumn('portfolio_cash_ledger_entries', 'strategy_id'));

        $second = $this->makeStrategy($profile, 'No Cash Split');
        app(StrategyRegistrySupport::class)->activate($profile, $second);

        $cashAfter = app(CashManagementService::class)->summary($profile);
        $this->assertEqualsWithDelta($cashBefore['cash_balance'], $cashAfter['cash_balance'], 0.0001);
        $this->assertEqualsWithDelta($cashBefore['reserved_cash'], $cashAfter['reserved_cash'], 0.0001);
        $this->assertEqualsWithDelta(
            $cashBefore['available_investable_cash'],
            $cashAfter['available_investable_cash'],
            0.0001
        );
        $this->assertSame(
            $ledgerCount,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
        $this->assertSame(1, CashAccount::query()->where('profile_id', $profile->id)->count());
    }

    public function test_editor_strategy_id_loads_the_requested_strategy(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Editor Target');
        app(StrategyRegistrySupport::class)->activate($profile, $second);

        $this->actingAs($user)
            ->getJson('/api/v1/strategy?strategy_id='.$second->id)
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.name', 'Editor Target');

        $this->actingAs($user)
            ->getJson('/api/v1/strategy')
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);
    }

    protected function makeStrategy(\App\Models\PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 100,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function makeStock(): Stock
    {
        return Stock::query()->create([
            'symbol' => 'ID'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Identity Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }
}
