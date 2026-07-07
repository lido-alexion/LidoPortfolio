<?php

namespace Tests\Unit;

use App\Models\CorporateAction;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\CorporateActionPriceRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorporateActionPriceRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_unadjusted_prices_for_legacy_corporate_action(): void
    {
        $user = User::query()->create([
            'name' => 'Repair User',
            'email' => 'repair-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'REPR',
            'exchange' => 'NSE',
            'name' => 'Repair Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->seedPrice($stock->id, '2026-01-10', 100);
        $this->seedPrice($stock->id, '2026-03-01', 52);

        $action = CorporateAction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
            'applied_at' => now(),
            'metadata' => ['factor' => 2],
        ]);

        $findings = app(CorporateActionPriceRepairService::class)->scan(actionId: $action->id);
        $this->assertCount(1, $findings);
        $this->assertSame(
            CorporateActionPriceRepairService::STATUS_SUSPECTED_UNADJUSTED,
            $findings[0]['status'],
        );
    }

    public function test_repair_dry_run_then_apply_restates_prices(): void
    {
        $user = User::query()->create([
            'name' => 'Repair Apply',
            'email' => 'repair-apply-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'REPA',
            'exchange' => 'NSE',
            'name' => 'Repair Apply Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->seedPrice($stock->id, '2026-01-10', 100);
        $this->seedPrice($stock->id, '2026-03-01', 50);

        CorporateAction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
            'applied_at' => now(),
            'metadata' => null,
        ]);

        $service = app(CorporateActionPriceRepairService::class);
        $dry = $service->repair(stockId: $stock->id, dryRun: true);
        $this->assertSame(1, $dry['repaired']);

        $apply = $service->repair(stockId: $stock->id, dryRun: false);
        $this->assertSame(1, $apply['repaired']);

        $this->assertEquals(50.0, (float) StockPrice::query()
            ->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-01-10')
            ->value('close_price'));

        $secondPass = $service->scan(stockId: $stock->id);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_OK, $secondPass[0]['status']);
    }

    protected function seedPrice(int $stockId, string $date, float $close): void
    {
        StockPrice::query()->create([
            'stock_id' => $stockId,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'adjusted_close_price' => $close,
            'volume' => 1000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }
}
