<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Models\User;
use App\Services\Fundamentals\FundamentalDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalSectorContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_include_sector_context_with_peer_percentile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $peerLow = Stock::query()->create([
            'symbol' => 'PEERL',
            'exchange' => 'NSE',
            'name' => 'Peer Low',
            'sector' => 'Technology',
            'is_active' => true,
        ]);
        $peerHigh = Stock::query()->create([
            'symbol' => 'PEERH',
            'exchange' => 'NSE',
            'name' => 'Peer High',
            'sector' => 'Technology',
            'is_active' => true,
        ]);
        $stock = Stock::query()->create([
            'symbol' => 'HERO',
            'exchange' => 'NSE',
            'name' => 'Hero Tech',
            'sector' => 'Technology',
            'is_active' => true,
        ]);

        $facts = app(FundamentalDataService::class);
        $this->seedRoeFacts($facts, $peerLow, 6);
        $this->seedRoeFacts($facts, $peerHigh, 20);
        $this->seedRoeFacts($facts, $stock, 18);

        foreach ([$peerLow, $peerHigh, $stock] as $member) {
            MlUniverseMembership::query()->create([
                'stock_id' => $member->id,
                'universe_key' => MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE,
                'effective_from' => '2025-01-01',
                'sector_snapshot' => 'Technology',
                'source' => 'test_authoritative_snapshot',
                'snapshot_key' => '2025-01-01',
            ]);
        }

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2025-06-20")
            ->assertOk()
            ->assertJsonPath('data.insights.sector_context.sector', 'Technology')
            ->assertJsonPath('data.insights.sector_context.peer_count', 2)
            ->assertJsonStructure([
                'data' => [
                    'insights' => [
                        'sector_context' => [
                            'peer_percentiles' => [
                                ['metric_key', 'label', 'value', 'peer_median', 'percentile'],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_missing_historical_snapshot_is_explicitly_not_current_peer_context(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'NOSNAP',
            'exchange' => 'NSE',
            'name' => 'No Snapshot Tech',
            'sector' => 'Technology',
            'is_active' => true,
        ]);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_insights=1&as_of=2024-01-15")
            ->assertOk()
            ->assertJsonPath('data.insights.sector_context.coverage_status', 'missing_historical_universe_snapshot')
            ->assertJsonPath('data.insights.sector_context.peer_count', 0);
    }

    private function seedRoeFacts(FundamentalDataService $facts, Stock $stock, float $netIncome): void
    {
        foreach (['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $facts->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'net_income',
                'period_end' => $period,
                'value' => $netIncome,
                'availability_date' => '2025-06-01',
            ]]);
        }
        $facts->storeFacts($stock, [[
            'statement_type' => 'balance_sheet',
            'cadence' => 'quarterly',
            'fact_key' => 'equity',
            'period_end' => '2025-03-31',
            'value' => 100,
            'availability_date' => '2025-06-01',
        ]]);
    }
}
