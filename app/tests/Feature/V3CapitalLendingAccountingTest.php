<?php

namespace Tests\Feature;

use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
use App\Models\CapitalRequest;
use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\ProfileSettingsService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Workstream 4 Step 2 — lent/borrowed and available_for_lending accounting.
 */
class V3CapitalLendingAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_no_loans_zero_lent_and_borrowed(): void
    {
        [$user, $profile, $first, $second] = $this->twoStrategyPortfolio(1_000_000);
        $this->setRecommendedMinimumHoldings($first, 5);
        $this->setRecommendedMinimumHoldings($second, 5);

        $data = $this->capitalJson($user, $profile);
        $byId = collect($data['strategies'])->keyBy('strategy_id');

        $this->assertEqualsWithDelta(0.0, $byId[$first->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$first->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$second->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$second->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$first->id]['already_committed_to_lending'], 0.0001);
        $this->assertEqualsWithDelta(750000.0, $byId[$first->id]['unused_allocation'], 0.0001);
        $this->assertEqualsWithDelta(750000.0, $byId[$first->id]['strategy_available_capital'], 0.0001);
        $this->assertEqualsWithDelta(600000.0, $byId[$first->id]['available_for_lending'], 0.0001);
        $this->assertEqualsWithDelta(200000.0, $byId[$second->id]['available_for_lending'], 0.0001);
        $this->assertNotEquals($data['od20']['unallocated_cash'], $byId[$first->id]['available_for_lending']);
    }

    public function test_outstanding_loan_sets_lent_and_borrowed(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $this->createLoan($profile, $lender, $borrower, 50_000, 50_000, CapitalLoan::STATUS_OUTSTANDING);

        $byId = collect($this->capitalJson($user, $profile)['strategies'])->keyBy('strategy_id');

        $this->assertEqualsWithDelta(50_000.0, $byId[$lender->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$lender->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(50_000.0, $byId[$borrower->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$borrower->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(700000.0, $byId[$lender->id]['unused_allocation'], 0.0001);
        $this->assertEqualsWithDelta(250000.0, $byId[$borrower->id]['unused_allocation'], 0.0001);
    }

    public function test_partial_return_uses_outstanding_not_principal(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000, 50_000, CapitalLoan::STATUS_OUTSTANDING);
        $loan->forceFill([
            'outstanding' => 30_000,
            'status' => CapitalLoan::STATUS_PARTIALLY_RETURNED,
        ])->save();
        CapitalLoanReturn::query()->create([
            'loan_id' => $loan->id,
            'capital_request_id' => $loan->capital_request_id,
            'amount' => 20_000,
            'returned_at' => now(),
            'created_at' => now(),
        ]);

        $byId = collect($this->capitalJson($user, $profile)['strategies'])->keyBy('strategy_id');
        $this->assertEqualsWithDelta(30_000.0, (float) $loan->fresh()->outstanding, 0.0001);
        $this->assertEqualsWithDelta(30_000.0, $byId[$lender->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(30_000.0, $byId[$borrower->id]['borrowed_capital'], 0.0001);
    }

    public function test_fully_returned_loan_contributes_zero(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000, 50_000, CapitalLoan::STATUS_OUTSTANDING);
        $loan->forceFill([
            'outstanding' => 0,
            'status' => CapitalLoan::STATUS_RETURNED,
        ])->save();

        $byId = collect($this->capitalJson($user, $profile)['strategies'])->keyBy('strategy_id');
        $this->assertEqualsWithDelta(0.0, $byId[$lender->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$borrower->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(750000.0, $byId[$lender->id]['unused_allocation'], 0.0001);
    }

    public function test_loan_rows_do_not_change_physical_cash_or_create_ledger_entries(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $before = $this->capitalJson($user, $profile);
        $ledgerBefore = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();

        $this->createLoan($profile, $lender, $borrower, 50_000, 50_000, CapitalLoan::STATUS_OUTSTANDING);

        $after = $this->capitalJson($user, $profile);
        $this->assertEqualsWithDelta(
            $before['physical_cash']['available_physical_cash'],
            $after['physical_cash']['available_physical_cash'],
            0.0001
        );
        $this->assertEqualsWithDelta(1_000_000.0, $after['physical_cash']['available_physical_cash'], 0.0001);
        $this->assertSame(1, $after['physical_cash']['cash_account_count']);
        $this->assertSame(0, $after['physical_cash']['strategy_physical_cash_accounts']);
        $this->assertFalse(Schema::hasColumn('portfolio_cash_accounts', 'strategy_id'));
        $this->assertSame(
            $ledgerBefore,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
        $this->assertSame(
            ['deposit'],
            CashLedgerEntry::query()->where('profile_id', $profile->id)->pluck('entry_type')->unique()->values()->all()
        );
        $this->assertSame(1, CashAccount::query()->where('profile_id', $profile->id)->count());
    }

    public function test_reserve_is_not_lendable_and_not_double_subtracted(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(20_000);
        $stock = $this->makeStock();
        $this->price($stock, 3000);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 10,
            'avg_buy_price' => 3000,
            'invested_amount' => 30_000,
            'updated_at' => now(),
        ]);
        app(ProfileSettingsService::class)->set($profile, 'portfolio_cash_reserve_pct', '20');

        $data = $this->capitalJson($user, $profile);
        $this->assertEqualsWithDelta(6000.0, $data['od19']['required_cash_reserve'], 0.0001);
        $row = $data['strategies'][0];
        $this->assertEqualsWithDelta(14_000.0, $row['unused_allocation'], 0.0001);
        $this->assertEqualsWithDelta(10_000.0, $row['available_for_lending'], 0.0001);
        $this->assertNotEquals(5_000.0, $row['available_for_lending']);
    }

    public function test_od24_reduces_available_for_lending_not_own_capital_buy_funding(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(750_000);
        $this->setRecommendedMinimumHoldings($strategy, 5);

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertSame(150000, $row['minimum_retained_capital']);
        $this->assertEqualsWithDelta(750000.0, $row['unused_allocation'], 0.0001);
        $this->assertEqualsWithDelta(750000.0, $row['strategy_available_capital'], 0.0001);
        $this->assertEqualsWithDelta(600000.0, $row['available_for_lending'], 0.0001);
    }

    public function test_lending_floor_on_snapshot(): void
    {
        foreach ([
            [4999, 0.0],
            [5000, 5000.0],
            [9999, 5000.0],
            [10000, 10000.0],
        ] as [$cash, $expectedAfl]) {
            [$user, $profile] = $this->cashOnlyPortfolio($cash);
            $row = $this->capitalJson($user, $profile)['strategies'][0];
            $this->assertEqualsWithDelta($expectedAfl, $row['available_for_lending'], 0.0001, 'cash '.$cash);
            $this->assertNull($row['minimum_retained_capital']);
        }
    }

    public function test_max_lending_pct_of_unused_caps_available_for_lending(): void
    {
        [$user, $profile] = $this->cashOnlyPortfolio(100_000);
        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertEqualsWithDelta(100_000.0, $row['unused_allocation'], 0.0001);
        $this->assertEqualsWithDelta(100_000.0, $row['available_for_lending'], 0.0001);

        app(ProfileSettingsService::class)->set($profile, 'max_lending_pct_of_unused', '50');

        $capped = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertEqualsWithDelta(100_000.0, $capped['unused_allocation'], 0.0001);
        $this->assertLessThanOrEqual(50_000.0, $capped['available_for_lending']);
        $this->assertEqualsWithDelta(50_000.0, $capped['available_for_lending'], 0.0001);
    }

    public function test_max_lending_absolute_caps_available_for_lending(): void
    {
        [$user, $profile] = $this->cashOnlyPortfolio(100_000);
        app(ProfileSettingsService::class)->set($profile, 'max_lending_absolute', '10000');

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertEqualsWithDelta(100_000.0, $row['unused_allocation'], 0.0001);
        $this->assertLessThanOrEqual(10_000.0, $row['available_for_lending']);
        $this->assertEqualsWithDelta(10_000.0, $row['available_for_lending'], 0.0001);
    }

    public function test_max_lending_caps_apply_before_floor_and_tighter_wins(): void
    {
        [$user, $profile] = $this->cashOnlyPortfolio(100_000);
        $settings = app(ProfileSettingsService::class);
        $settings->set($profile, 'max_lending_pct_of_unused', '50');
        $settings->set($profile, 'max_lending_absolute', '15000');

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        // min(100000, unused*50%=50000, 15000) → floor 15000
        $this->assertEqualsWithDelta(15_000.0, $row['available_for_lending'], 0.0001);

        $settings->set($profile, 'max_lending_absolute', '12000');
        $floored = $this->capitalJson($user, $profile)['strategies'][0];
        // min(..., 12000) → FloorToRupee5000 → 10000 (12000 is not a ₹5k multiple)
        $this->assertEqualsWithDelta(10_000.0, $floored['available_for_lending'], 0.0001);

        $settings->set($profile, 'max_lending_absolute', '9999');
        $flooredLow = $this->capitalJson($user, $profile)['strategies'][0];
        // min(..., 9999) → FloorToRupee5000 → 5000
        $this->assertEqualsWithDelta(5_000.0, $flooredLow['available_for_lending'], 0.0001);
    }

    public function test_empty_max_lending_settings_preserve_full_surplus(): void
    {
        [$user, $profile] = $this->cashOnlyPortfolio(100_000);
        app(ProfileSettingsService::class)->set($profile, 'max_lending_pct_of_unused', '');
        app(ProfileSettingsService::class)->set($profile, 'max_lending_absolute', '');

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertEqualsWithDelta(100_000.0, $row['available_for_lending'], 0.0001);
    }

    public function test_lending_does_not_modify_allocation_pct(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $this->assertEqualsWithDelta(75.0, (float) $lender->allocation_pct, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $borrower->allocation_pct, 0.0001);

        $this->createLoan($profile, $lender, $borrower, 50_000, 50_000, CapitalLoan::STATUS_OUTSTANDING);

        $this->assertEqualsWithDelta(75.0, (float) $lender->fresh()->allocation_pct, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $borrower->fresh()->allocation_pct, 0.0001);
        $byId = collect($this->capitalJson($user, $profile)['strategies'])->keyBy('strategy_id');
        $this->assertEqualsWithDelta(75.0, $byId[$lender->id]['allocation_pct'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $byId[$borrower->id]['allocation_pct'], 0.0001);
    }

    public function test_lending_does_not_create_lender_holdings_or_change_borrower_ownership(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $stock = $this->makeStock();
        $this->price($stock, 100);
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $borrower->id,
            'owner_key' => Holding::ownerKeyFor((int) $borrower->id),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);

        $this->createLoan($profile, $lender, $borrower, 50_000, 50_000, CapitalLoan::STATUS_OUTSTANDING);

        $holding->refresh();
        $this->assertSame((int) $borrower->id, (int) $holding->strategy_id);
        $this->assertSame(Holding::ownerKeyFor((int) $borrower->id), $holding->owner_key);
        $this->assertSame(1, Holding::query()->where('profile_id', $profile->id)->count());
        $this->assertFalse(
            Holding::query()
                ->where('profile_id', $profile->id)
                ->where('strategy_id', $lender->id)
                ->exists()
        );
    }

    public function test_displayed_capital_request_without_loan_is_not_lent_capital(): void
    {
        [$user, $profile, $lender, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => 50_000,
            'status' => CapitalRequest::STATUS_DISPLAYED,
        ]);

        $byId = collect($this->capitalJson($user, $profile)['strategies'])->keyBy('strategy_id');
        $this->assertEqualsWithDelta(0.0, $byId[$lender->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$lender->id]['already_committed_to_lending'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byId[$borrower->id]['borrowed_capital'], 0.0001);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    protected function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Strategy B');
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/allocations', [
                'allocations' => [
                    ['strategy_id' => $first->id, 'allocation_pct' => 75],
                    ['strategy_id' => $second->id, 'allocation_pct' => 25],
                ],
            ])
            ->assertOk();

        return [$user, $profile, $first->fresh(['activeVersion']), $second->fresh(['activeVersion'])];
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy}
     */
    protected function cashOnlyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $strategy->forceFill(['allocation_pct' => 100])->save();
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);

        return [$user, $profile, $strategy->fresh(['activeVersion'])];
    }

    protected function createLoan(
        PortfolioProfile $profile,
        TradingStrategy $lender,
        TradingStrategy $borrower,
        float $principal,
        float $outstanding,
        string $status,
    ): CapitalLoan {
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => $principal,
            'status' => CapitalRequest::STATUS_COMMITTED,
            'approved_at' => now(),
        ]);

        return CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => $principal,
            'outstanding' => $outstanding,
            'committed_at' => now(),
            'status' => $status,
        ]);
    }

    protected function makeRecommendation(PortfolioProfile $profile, TradingStrategy $borrower): TradingRecommendation
    {
        $stock = $this->makeStock();

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
    }

    protected function setRecommendedMinimumHoldings(TradingStrategy $strategy, int $count): void
    {
        $version = $strategy->activeVersion
            ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
        $config = is_array($version?->config_json) ? $version->config_json : [];
        $config['recommended_minimum_holdings'] = $count;
        $version->forceFill(['config_json' => $config])->save();
    }

    protected function capitalJson(User $user, PortfolioProfile $profile): array
    {
        return $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital')
            ->assertOk()
            ->json('data');
    }

    protected function makeStrategy(PortfolioProfile $profile, string $name): TradingStrategy
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
            'symbol' => 'LA'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Lending Accounting Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function price(Stock $stock, float $close): void
    {
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);
    }
}
