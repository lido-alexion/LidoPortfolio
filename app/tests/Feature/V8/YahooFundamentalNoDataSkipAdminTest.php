<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\FundamentalUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class YahooFundamentalNoDataSkipAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_remove_a_skip_and_removal_resets_the_counter_with_audit(): void
    {
        $stock = Stock::query()->create(['symbol' => 'SKIPCO', 'exchange' => 'NSE', 'name' => 'Skip Company']);
        DB::table('stox_yahoo_fundamental_symbol_state')->insert([
            'stock_id' => $stock->id,
            'consecutive_no_data' => 20,
            'last_no_data_at' => now(),
            'skipped_at' => now(),
            'skip_reason' => '20 consecutive valid Yahoo no-data outcomes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $member = User::factory()->create(['is_admin' => false]);
        $this->actingAs($member)->deleteJson("/api/v1/admin/fundamentals/yahoo-no-data-skips/{$stock->id}")->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $this->actingAs($admin)->withProfileHeader($admin)
            ->deleteJson("/api/v1/admin/fundamentals/yahoo-no-data-skips/{$stock->id}")
            ->assertOk()
            ->assertJsonPath('data.consecutive_no_data', 0)
            ->assertJsonPath('data.skipped_at', null);

        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        $this->assertSame(0, (int) $state->consecutive_no_data);
        $this->assertNull($state->skipped_at);
        $this->assertSame('admin_removed_skip', DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stock->id)->value('outcome'));
    }

    public function test_admin_manual_fetch_uses_the_skip_override_scope_and_leaves_removal_explicit(): void
    {
        $stock = Stock::query()->create(['symbol' => 'BYPASSCO', 'exchange' => 'NSE', 'name' => 'Bypass Company']);
        DB::table('stox_yahoo_fundamental_symbol_state')->insert([
            'stock_id' => $stock->id,
            'consecutive_no_data' => 20,
            'last_no_data_at' => now(),
            'skipped_at' => now(),
            'skip_reason' => '20 consecutive valid Yahoo no-data outcomes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'admin_manual_yahoo', 'scope' => 'manual_stock', 'status' => 'queued']);
        $updates = Mockery::mock(FundamentalUpdateService::class);
        $updates->shouldReceive('createRun')->times(3)->with('admin_manual_yahoo', 'manual_stock', $stock->id, 1)->andReturn($run);
        $updates->shouldReceive('process')->times(3)->with($run, 2)->andReturn(['status' => 'completed']);
        $this->app->instance(FundamentalUpdateService::class, $updates);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson("/api/v1/admin/fundamentals/yahoo-no-data-skips/{$stock->id}/fetch")
            ->assertCreated();
        $this->actingAs($admin)->withProfileHeader($admin)->postJson("/api/v1/admin/fundamentals/yahoo-no-data-skips/{$stock->id}/fetch")->assertCreated();
        $this->actingAs($admin)->withProfileHeader($admin)->postJson("/api/v1/admin/fundamentals/yahoo-no-data-skips/{$stock->id}/fetch")->assertCreated();
        $this->actingAs($admin)->withProfileHeader($admin)->postJson("/api/v1/admin/fundamentals/yahoo-no-data-skips/{$stock->id}/fetch")->assertTooManyRequests();

        $this->assertNotNull(DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->value('skipped_at'));
        $this->assertSame(20, (int) DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->value('consecutive_no_data'));
    }
}
