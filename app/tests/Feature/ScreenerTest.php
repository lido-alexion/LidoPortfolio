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
            ->assertJsonPath('data.0.name', 'Shared RSI')
            ->assertJsonPath('data.0.definition_json.root.type', 'group')
            ->assertJsonMissingPath('data.0.source_profile')
            ->assertJsonMissingPath('data.0.profile_id')
            ->assertJsonMissingPath('data.0.schedule_enabled')
            ->assertJsonMissingPath('data.0.is_shared');

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

    public function test_index_scope_runs_against_constituents(): void
    {
        $user = User::query()->create([
            'name' => 'Index Screener User',
            'email' => 'scr-idx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);

        $inIndex = Stock::query()->create([
            'symbol' => 'IDXIN'.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => 'In Index',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $outIndex = Stock::query()->create([
            'symbol' => 'IDXOUT'.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => 'Out Index',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        \App\Models\Setting::setValue('index_constituents_nifty50_json', json_encode([$inIndex->symbol]));
        \App\Models\Setting::setValue('index_constituents_nifty50_cached_at', now()->toIso8601String());

        $base = now()->subDays(30)->startOfDay();
        foreach ([$inIndex, $outIndex] as $stock) {
            for ($i = 0; $i < 25; $i++) {
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
        }

        $this->actingAs($user);

        $this->getJson('/api/screeners/meta')
            ->assertOk()
            ->assertJsonFragment(['id' => 'index', 'label' => 'Index constituents'])
            ->assertJsonFragment(['symbol' => 'NIFTY50']);

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
            'name' => 'Nifty50 screen',
            'scope' => 'index',
            'definition_json' => $definition,
        ])->assertStatus(422);

        $this->postJson('/api/screeners', [
            'name' => 'Nifty50 screen',
            'scope' => 'index',
            'index_symbol' => 'SENSEX',
            'definition_json' => $definition,
        ])->assertStatus(422);

        $create = $this->postJson('/api/screeners', [
            'name' => 'Nifty50 screen',
            'scope' => 'index',
            'index_symbol' => 'nifty50',
            'definition_json' => $definition,
            'telegram_enabled' => false,
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.scope', 'index');
        $create->assertJsonPath('data.index_symbol', 'NIFTY50');
        $create->assertJsonPath('data.index.symbol', 'NIFTY50');
        $id = $create->json('data.id');

        $run = $this->postJson("/api/screeners/{$id}/run")->assertOk();
        $run->assertJsonPath('data.stats.scanned', 1);
        $run->assertJsonPath('data.stats.matched', 1);
        $runId = $run->json('data.id');

        $this->getJson("/api/screener-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.hits.data.0.symbol', $inIndex->symbol);
    }

    public function test_index_scope_empty_constituents_warns(): void
    {
        $user = User::query()->create([
            'name' => 'Empty Index Screener',
            'email' => 'scr-empty-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close', 'params' => []],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 0],
            ],
        ];

        $create = $this->postJson('/api/screeners', [
            'name' => 'Empty Nifty Bank',
            'scope' => 'index',
            'index_symbol' => 'NIFTYBANK',
            'definition_json' => $definition,
            'telegram_enabled' => false,
        ])->assertCreated();
        $id = $create->json('data.id');

        $run = $this->postJson("/api/screeners/{$id}/run")->assertOk();
        $run->assertJsonPath('data.stats.scanned', 0);
        $warnings = $run->json('data.stats.warnings') ?? [];
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('constituents', strtolower(implode(' ', $warnings)));
    }

    public function test_compare_runs_matrix_stacks_hits(): void
    {
        $user = User::query()->create([
            'name' => 'Compare Runs User',
            'email' => 'scr-cmp-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/screeners', [
            'name' => 'Compare screen',
            'scope' => 'holdings',
            'definition_json' => [
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
            ],
        ])->assertCreated();
        $screenerId = (int) $create->json('data.id');

        $stockA = Stock::query()->create([
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'Alpha',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $stockB = Stock::query()->create([
            'symbol' => 'BBB',
            'exchange' => 'NSE',
            'name' => 'Beta',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $stockC = Stock::query()->create([
            'symbol' => 'CCC',
            'exchange' => 'NSE',
            'name' => 'Gamma',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $empty = $this->getJson("/api/screeners/{$screenerId}/runs/compare")
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $empty['run_count']);
        $this->assertSame([], $empty['columns']);
        $this->assertSame([], $empty['rows']);

        $runA = \App\Models\ScreenerRun::query()->create([
            'screener_id' => $screenerId,
            'triggered_by' => 'manual',
            'status' => 'completed',
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3)->addMinutes(1),
            'stats_json' => ['matched' => 2],
        ]);
        $runB = \App\Models\ScreenerRun::query()->create([
            'screener_id' => $screenerId,
            'triggered_by' => 'schedule',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2)->addMinutes(1),
            'stats_json' => ['matched' => 1],
        ]);
        $runC = \App\Models\ScreenerRun::query()->create([
            'screener_id' => $screenerId,
            'triggered_by' => 'manual',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinutes(1),
            'stats_json' => ['matched' => 2],
        ]);
        \App\Models\ScreenerRun::query()->create([
            'screener_id' => $screenerId,
            'triggered_by' => 'manual',
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'stats_json' => ['matched' => 0],
        ]);

        \App\Models\ScreenerRunHit::query()->create([
            'run_id' => $runA->id,
            'stock_id' => $stockA->id,
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'Alpha',
            'metrics_json' => [],
        ]);
        \App\Models\ScreenerRunHit::query()->create([
            'run_id' => $runA->id,
            'stock_id' => $stockB->id,
            'symbol' => 'BBB',
            'exchange' => 'NSE',
            'name' => 'Beta',
            'metrics_json' => [],
        ]);
        \App\Models\ScreenerRunHit::query()->create([
            'run_id' => $runB->id,
            'stock_id' => $stockA->id,
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'Alpha',
            'metrics_json' => [],
        ]);
        \App\Models\ScreenerRunHit::query()->create([
            'run_id' => $runC->id,
            'stock_id' => $stockA->id,
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'Alpha',
            'metrics_json' => [],
        ]);
        \App\Models\ScreenerRunHit::query()->create([
            'run_id' => $runC->id,
            'stock_id' => $stockC->id,
            'symbol' => 'CCC',
            'exchange' => 'NSE',
            'name' => 'Gamma',
            'metrics_json' => [],
        ]);

        $matrix = $this->getJson("/api/screeners/{$screenerId}/runs/compare")
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $matrix['run_count']);
        $this->assertSame(3, $matrix['stock_count']);
        $this->assertSame([$runA->id, $runB->id, $runC->id], array_column($matrix['columns'], 'id'));
        $this->assertSame('Scheduled', $matrix['columns'][1]['trigger_label']);

        $bySymbol = collect($matrix['rows'])->keyBy('symbol');
        $this->assertSame([true, true, true], $bySymbol['AAA']['presence']);
        $this->assertSame([true, false, false], $bySymbol['BBB']['presence']);
        $this->assertSame([false, false, true], $bySymbol['CCC']['presence']);
        $this->assertSame('AAA', $matrix['rows'][0]['symbol']);
    }

    public function test_weight_factor_persists_and_rejects_invalid(): void
    {
        $user = User::query()->create([
            'name' => 'Weight Factor User',
            'email' => 'scr-wf-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'close'],
                        'operator' => 'gt',
                        'weight_factor' => 0.5,
                        'right' => ['type' => 'constant', 'value' => 100],
                    ],
                ],
            ],
        ];

        $create = $this->postJson('/api/screeners', [
            'name' => 'Weighted screen',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated();

        $this->assertSame(0.5, $create->json('data.definition_json.root.children.0.weight_factor'));

        $this->postJson('/api/screeners', [
            'name' => 'Bad weight',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'weight_factor' => 'abc',
                    'right' => ['type' => 'constant', 'value' => 1],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_left_entity_runs_against_index_bars_and_validates(): void
    {
        $user = User::query()->create([
            'name' => 'Entity User',
            'email' => 'scr-ent-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        // Holding stock with a wide intraday range (range_pct = 20/100 = 20%).
        $stock = Stock::query()->create([
            'symbol' => 'ENT'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Entity Stock',
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
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDay()->toDateString(),
            'open_price' => 100,
            'high_price' => 110,
            'low_price' => 90,
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'volume' => 10000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        // NIFTY50 benchmark with a narrow range (range_pct = 100/25000 = 0.4%).
        $index = Stock::query()->create([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'name' => 'Nifty 50',
            'is_active' => true,
            'is_benchmark' => true,
        ]);
        StockPrice::query()->create([
            'stock_id' => $index->id,
            'price_date' => now()->subDay()->toDateString(),
            'open_price' => 25000,
            'high_price' => 25050,
            'low_price' => 24950,
            'close_price' => 25000,
            'adjusted_close_price' => 25000,
            'volume' => null,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $this->actingAs($user);

        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['entity' => 'NIFTY50', 'indicator' => 'range_pct', 'params' => []],
                'operator' => 'lt',
                'right' => ['indicator' => 'range_pct', 'params' => []],
            ],
        ];

        $create = $this->postJson('/api/screeners', [
            'name' => 'Beats index range',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated();
        $this->assertSame('NIFTY50', $create->json('data.definition_json.root.left.entity'));
        $id = $create->json('data.id');

        $run = $this->postJson("/api/screeners/{$id}/run");
        $run->assertOk();
        $run->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, $run->json('data.stats.matched'));

        $runId = $run->json('data.id');
        $hit = $this->getJson("/api/screener-runs/{$runId}")->json('data.hits.data.0');
        $this->assertSame('NIFTY50', $hit['metrics'][0]['left_entity']);
        $this->assertSame('Nifty 50 range_pct', $hit['metrics'][0]['left']);

        // Unknown entity on the left is rejected.
        $this->postJson('/api/screeners', [
            'name' => 'Bad entity',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['entity' => 'NIFTYBANK', 'indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
        ])->assertStatus(422);

        // Entity on the right side is rejected (RHS always evaluates on the stock).
        $this->postJson('/api/screeners', [
            'name' => 'Bad RHS entity',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['entity' => 'NIFTY50', 'indicator' => 'close'],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_backtest_weekdays_matrix_and_session_discard(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-21 12:00:00', config('app.timezone')));

        $user = User::query()->create([
            'name' => 'Backtest User',
            'email' => 'scr-bt-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'BT'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Backtest Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        $base = now()->subDays(40)->startOfDay();
        for ($i = 0; $i < 45; $i++) {
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
            ->assertJsonPath('data.backtest_scopes.0', 'holdings')
            ->assertJsonFragment(['id' => '15d', 'label' => '15 days']);

        $create = $this->postJson('/api/screeners', [
            'name' => 'Backtest screen',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        [
                            'type' => 'condition',
                            'left' => ['indicator' => 'close'],
                            'operator' => 'gt',
                            'weight_factor' => 1,
                            'right' => ['type' => 'constant', 'value' => 0],
                        ],
                    ],
                ],
            ],
        ])->assertCreated();
        $id = (int) $create->json('data.id');
        $token = 'test-backtest-session-'.Str::random(8);

        $start = $this->postJson("/api/screeners/{$id}/backtest", [
            'range' => '15d',
            'session_token' => $token,
        ])->assertOk();

        $backtestId = (int) $start->json('data.id');
        $completed = (bool) $start->json('completed');
        $guard = 0;
        while (! $completed && $guard < 50) {
            $guard++;
            $cont = $this->postJson("/api/screener-backtests/{$backtestId}/continue")->assertOk();
            $completed = (bool) $cont->json('completed');
        }
        $this->assertTrue($completed);

        $matrix = $this->getJson("/api/screener-backtests/{$backtestId}/matrix")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($matrix['columns']);
        foreach ($matrix['columns'] as $col) {
            $dow = \Carbon\Carbon::parse($col['id'])->dayOfWeek;
            $this->assertNotContains($dow, [\Carbon\Carbon::SATURDAY, \Carbon\Carbon::SUNDAY]);
        }
        $this->assertSame($stock->symbol, $matrix['rows'][0]['symbol']);
        $this->assertTrue(in_array(true, $matrix['rows'][0]['presence'], true));

        // All scopes are backtestable with the stock-major engine, including the
        // full equity universe.
        $this->getJson('/api/screeners/meta')
            ->assertOk()
            ->assertJsonFragment(['backtest_scopes' => ['holdings', 'watchlist', 'all_equities', 'index']]);

        $universeCreate = $this->postJson('/api/screeners', [
            'name' => 'Universe backtest',
            'scope' => 'all_equities',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
        ])->assertCreated();
        $universeId = (int) $universeCreate->json('data.id');

        $uniStart = $this->postJson("/api/screeners/{$universeId}/backtest", [
            'range' => '15d',
            'session_token' => $token.'-x',
        ])->assertOk();
        $uniBtId = (int) $uniStart->json('data.id');
        $uniCompleted = (bool) $uniStart->json('completed');
        $guard = 0;
        while (! $uniCompleted && $guard < 50) {
            $guard++;
            $uniCompleted = (bool) $this->postJson("/api/screener-backtests/{$uniBtId}/continue")->assertOk()->json('completed');
        }
        $this->assertTrue($uniCompleted);
        $uniMatrix = $this->getJson("/api/screener-backtests/{$uniBtId}/matrix")->assertOk()->json('data');
        $this->assertNotEmpty($uniMatrix['columns']);
        $uniSymbols = array_column($uniMatrix['rows'], 'symbol');
        $this->assertContains($stock->symbol, $uniSymbols);
        $this->deleteJson("/api/screener-backtests/session/{$token}-x")->assertOk();

        $this->deleteJson("/api/screener-backtests/session/{$token}")
            ->assertOk()
            ->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('portfolio_screener_backtests', ['id' => $backtestId]);

        // Per-date results are persistent: they survive the session discard
        // and are served by the screener-level matrix endpoint.
        $this->assertDatabaseHas('portfolio_screener_backtest_days', ['screener_id' => $id]);
        $persisted = $this->getJson("/api/screeners/{$id}/backtest/matrix")
            ->assertOk()
            ->json('data');
        $this->assertSame(count($matrix['columns']), count($persisted['columns']));
        $this->assertSame($stock->symbol, $persisted['rows'][0]['symbol']);

        \Carbon\Carbon::setTestNow();
    }

    public function test_completed_run_fills_backtest_day_cache_and_backtest_reuses_it(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-21 18:30:00', config('app.timezone')));

        $user = User::query()->create([
            'name' => 'Run Cache User',
            'email' => 'scr-rc-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'RC'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Run Cache Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
        $base = now()->subDays(40)->startOfDay();
        for ($i = 0; $i < 45; $i++) {
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

        $create = $this->postJson('/api/screeners', [
            'name' => 'Run cache screen',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
        ])->assertCreated();
        $id = (int) $create->json('data.id');

        // A completed run (manual here; scheduled cron runs use the same path)
        // writes today's result into the per-date backtest cache.
        $this->postJson("/api/screeners/{$id}/run")->assertOk();
        $today = now()->toDateString();
        $day = \App\Models\ScreenerBacktestDay::query()
            ->where('screener_id', $id)
            ->where('as_of_date', $today)
            ->first();
        $this->assertNotNull($day);
        $this->assertSame(1, (int) $day->matched);
        $this->assertSame($stock->symbol, \App\Models\ScreenerBacktestHit::query()
            ->where('screener_id', $id)
            ->where('as_of_date', $today)
            ->value('symbol'));

        // Running again the same day overwrites (still one row per date).
        $this->postJson("/api/screeners/{$id}/run")->assertOk();
        $this->assertSame(1, \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());

        // A 15-day backtest reuses the run-filled date and computes only the rest.
        $start = $this->postJson("/api/screeners/{$id}/backtest", [
            'range' => '15d',
            'session_token' => 'rc-'.Str::random(8),
        ])->assertOk();
        $btId = (int) $start->json('data.id');
        $completed = (bool) $start->json('completed');
        $data = $start->json('data');
        $guard = 0;
        while (! $completed && $guard < 50) {
            $guard++;
            $cont = $this->postJson("/api/screener-backtests/{$btId}/continue")->assertOk();
            $completed = (bool) $cont->json('completed');
            $data = $cont->json('data');
        }
        $this->assertTrue($completed);
        $this->assertSame(1, (int) $data['stats']['days_reused']);
        $this->assertSame((int) $data['stats']['day_total'], \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());

        \Carbon\Carbon::setTestNow();
    }

    public function test_backtest_reuses_saved_dates_and_clears_on_edit_or_clear_history(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-21 12:00:00', config('app.timezone')));

        $user = User::query()->create([
            'name' => 'Backtest Cache User',
            'email' => 'scr-btc-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'BTC'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Backtest Cache Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
        $base = now()->subDays(40)->startOfDay();
        for ($i = 0; $i < 45; $i++) {
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

        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close'],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 0],
            ],
        ];
        $create = $this->postJson('/api/screeners', [
            'name' => 'Backtest cache screen',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertCreated();
        $id = (int) $create->json('data.id');
        $token = 'bt-cache-'.Str::random(8);

        $runBacktest = function (string $sessionToken) use ($id) {
            $start = $this->postJson("/api/screeners/{$id}/backtest", [
                'range' => '15d',
                'session_token' => $sessionToken,
            ])->assertOk();
            $btId = (int) $start->json('data.id');
            $completed = (bool) $start->json('completed');
            $data = $start->json('data');
            $guard = 0;
            while (! $completed && $guard < 50) {
                $guard++;
                $cont = $this->postJson("/api/screener-backtests/{$btId}/continue")->assertOk();
                $completed = (bool) $cont->json('completed');
                $data = $cont->json('data');
            }
            $this->assertTrue($completed);

            return $data;
        };

        // First run computes everything; nothing reused.
        $first = $runBacktest($token.'-1');
        $dayTotal = (int) $first['stats']['day_total'];
        $this->assertGreaterThan(0, $dayTotal);
        $this->assertSame(0, (int) $first['stats']['days_reused']);
        $this->assertSame($dayTotal, \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());

        // Second run: every date already saved → fully reused, zero scanning.
        // Deleting the price history proves results come from the DB, not recomputation.
        StockPrice::query()->where('stock_id', $stock->id)->delete();
        $second = $runBacktest($token.'-2');
        $this->assertSame($dayTotal, (int) $second['stats']['days_reused']);
        $this->assertSame(0, (int) $second['stats']['scanned']);
        $matrix = $this->getJson("/api/screeners/{$id}/backtest/matrix")->assertOk()->json('data');
        $this->assertSame($stock->symbol, $matrix['rows'][0]['symbol']);

        // Saving without changing conditions keeps saved results.
        $this->putJson("/api/screeners/{$id}", [
            'name' => 'Backtest cache screen renamed',
            'scope' => 'holdings',
            'definition_json' => $definition,
        ])->assertOk();
        $this->assertSame($dayTotal, \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());

        // Changing conditions invalidates saved backtest results.
        $this->putJson("/api/screeners/{$id}", [
            'name' => 'Backtest cache screen renamed',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 5],
                ],
            ],
        ])->assertOk();
        $this->assertSame(0, \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());
        $this->assertSame(0, \App\Models\ScreenerBacktestHit::query()->where('screener_id', $id)->count());

        // Rebuild results, then Clear history wipes them too.
        $third = $runBacktest($token.'-3');
        $this->assertSame(0, (int) $third['stats']['days_reused']);
        $this->assertSame($dayTotal, \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());
        $this->deleteJson("/api/screeners/{$id}/runs")
            ->assertOk()
            ->assertJsonPath('backtest_days_cleared', $dayTotal);
        $this->assertSame(0, \App\Models\ScreenerBacktestDay::query()->where('screener_id', $id)->count());
        $empty = $this->getJson("/api/screeners/{$id}/backtest/matrix")->assertOk()->json('data');
        $this->assertSame(0, $empty['run_count']);

        \Carbon\Carbon::setTestNow();
    }
}
