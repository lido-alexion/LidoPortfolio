<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScreenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_crud_and_run_on_holdings(): void
    {
        $user = User::query()->create([
            'name' => 'Screener User',
            'email' => 'scr-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'S'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Screener Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        $base = now()->subDays(40)->startOfDay();
        for ($i = 0; $i < 40; $i++) {
            $c = 100 + $i;
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $base->copy()->addDays($i)->toDateString(),
                'open_price' => $c,
                'high_price' => $c + 1,
                'low_price' => $c - 1,
                'close_price' => $c,
                'adjusted_close_price' => $c,
                'volume' => 10000,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($user);

        $this->getJson('/api/screeners/meta')
            ->assertOk()
            ->assertJsonPath('data.max_conditions', 40)
            ->assertJsonStructure(['data' => ['indicators', 'operators', 'scopes']]);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'sma', 'params' => ['period' => 5]],
                        'operator' => 'gt',
                        'right' => ['indicator' => 'sma', 'params' => ['period' => 10]],
                    ],
                ],
            ],
        ];

        $create = $this->postJson('/api/screeners', [
            'name' => 'Uptrend SMA',
            'scope' => 'holdings',
            'telegram_enabled' => false,
            'definition_json' => $definition,
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.scope', 'holdings');
        $create->assertJsonPath('data.max_lookback', 10);
        $id = $create->json('data.id');

        $this->getJson('/api/screeners')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->putJson("/api/screeners/{$id}", [
            'name' => 'Uptrend SMA v2',
            'scope' => 'holdings',
            'telegram_enabled' => false,
            'definition_json' => $definition,
            'schedule_enabled' => true,
            'schedule_time' => '09:15',
            'schedule_days' => [1, 2, 3, 4, 5],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Uptrend SMA v2')
            ->assertJsonPath('data.schedule_enabled', true);

        $run = $this->postJson("/api/screeners/{$id}/run");
        $run->assertOk();
        $run->assertJsonPath('completed', true);
        $run->assertJsonPath('data.status', 'completed');
        $this->assertGreaterThanOrEqual(1, $run->json('data.stats.scanned'));
        $this->assertGreaterThanOrEqual(1, $run->json('data.stats.matched'));

        $runId = $run->json('data.id');
        $this->getJson("/api/screener-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.hits.total', fn ($t) => $t >= 1);

        $this->getJson("/api/screeners/{$id}/runs")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/screeners/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_screeners', ['id' => $id]);
    }

    public function test_insufficient_history_is_skipped_not_matched(): void
    {
        $user = User::query()->create([
            'name' => 'Screener Skip',
            'email' => 'scr2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'T'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Thin History',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 10,
            'invested_amount' => 10,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        // Only 5 bars — not enough for EMA 50
        for ($i = 0; $i < 5; $i++) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays(5 - $i)->toDateString(),
                'open_price' => 10,
                'high_price' => 11,
                'low_price' => 9,
                'close_price' => 10 + $i,
                'volume' => 100,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($user);

        $create = $this->postJson('/api/screeners', [
            'name' => 'Needs history',
            'scope' => 'holdings',
            'telegram_enabled' => false,
            'definition_json' => [
                'root' => [
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        [
                            'type' => 'condition',
                            'left' => ['indicator' => 'ema', 'params' => ['period' => 50]],
                            'operator' => 'gt',
                            'right' => ['type' => 'constant', 'value' => 0],
                        ],
                    ],
                ],
            ],
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $run = $this->postJson("/api/screeners/{$id}/run");
        $run->assertOk();
        $run->assertJsonPath('data.stats.matched', 0);
        $run->assertJsonPath('data.stats.skipped_insufficient_data', 1);
        $run->assertJsonPath('data.stats.scanned', 1);
    }

    public function test_shared_list_and_import(): void
    {
        $user = User::query()->create([
            'name' => 'Share User',
            'email' => 'share-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $ownerProfile = $this->defaultPortfolioFor($user);
        $otherProfile = $this->createPortfolioProfile($user, 'Secondary', false);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]],
                        'operator' => 'lt',
                        'right' => ['type' => 'constant', 'value' => 30],
                    ],
                ],
            ],
        ];

        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $ownerProfile->id);

        $create = $this->postJson('/api/screeners', [
            'name' => 'Shared RSI',
            'scope' => 'holdings',
            'is_shared' => true,
            'definition_json' => $definition,
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.is_shared', true);
        $sharedId = $create->json('data.id');

        $this->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $sharedId)
            ->assertJsonPath('data.0.source_profile.name', $ownerProfile->name);

        $import = $this->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import");
        $import->assertCreated();
        $import->assertJsonPath('data.is_shared', false);
        $import->assertJsonPath('data.profile_id', $otherProfile->id);
        $import->assertJsonPath('data.name', 'Shared RSI');

        $this->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/screeners')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_name_and_description_validation_rules(): void
    {
        $user = User::query()->create([
            'name' => 'Validation User',
            'email' => 'val-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->actingAs($user);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'close', 'params' => []],
                        'operator' => 'gt',
                        'right' => ['type' => 'constant', 'value' => 0],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/screeners', [
            'name' => str_repeat('A', 121),
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson('/api/screeners', [
            'name' => 'Bad 😀 Name',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson('/api/screeners', [
            'name' => 'Valid Name',
            'description' => str_repeat('B', 501),
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_list_reports_watchlist_issue_and_last_run_warnings(): void
    {
        $user = User::query()->create([
            'name' => 'Watchlist Issue User',
            'email' => 'wl-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'close', 'params' => []],
                        'operator' => 'gt',
                        'right' => ['type' => 'constant', 'value' => 0],
                    ],
                ],
            ],
        ];

        $create = $this->postJson('/api/screeners', [
            'name' => 'Broken watchlist screen',
            'scope' => 'watchlist',
            'watchlist_id' => 999999,
            'definition_json' => $definition,
        ]);
        $create->assertStatus(422);

        $screener = \App\Models\Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Orphan watchlist screen',
            'scope' => 'watchlist',
            'watchlist_id' => null,
            'definition_json' => $definition,
            'schedule_enabled' => false,
            'telegram_enabled' => false,
            'is_enabled' => true,
            'is_shared' => false,
        ]);

        $run = $this->postJson("/api/screeners/{$screener->id}/run");
        $run->assertOk();
        $run->assertJsonPath('data.stats.warnings.0', 'Watchlist missing; empty set.');

        $list = $this->getJson('/api/screeners');
        $list->assertOk()
            ->assertJsonPath('data.0.watchlist_issue', 'Watchlist missing or was deleted. Select a watchlist to run this screener.')
            ->assertJsonPath('data.0.last_run.stats.warnings.0', 'Watchlist missing; empty set.');
    }

    public function test_clear_run_history(): void
    {
        $user = User::query()->create([
            'name' => 'Clear Runs User',
            'email' => 'clr-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'close', 'params' => []],
                        'operator' => 'gt',
                        'right' => ['type' => 'constant', 'value' => 0],
                    ],
                ],
            ],
        ];

        $create = $this->postJson('/api/screeners', [
            'name' => 'Clearable screen',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $this->postJson("/api/screeners/{$id}/run")->assertOk();
        $this->getJson("/api/screeners/{$id}/runs")
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->deleteJson("/api/screeners/{$id}/runs")
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->getJson("/api/screeners/{$id}/runs")
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->assertDatabaseMissing('portfolio_screener_runs', ['screener_id' => $id]);
    }
}
