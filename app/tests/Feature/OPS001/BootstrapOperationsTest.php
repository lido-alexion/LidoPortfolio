<?php

namespace Tests\Feature\OPS001;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapOperationsService;
use App\Services\Fundamentals\FundamentalBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BootstrapOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_request_snapshots_actor_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPS1', 'exchange' => 'NSE', 'name' => 'Ops One', 'is_active' => true, 'is_benchmark' => false]);
        $ops = app(BootstrapOperationsService::class);
        $request = ['scope' => 'stocks', 'stock_ids' => [$stock->id], 'trigger' => 'manual'];

        $firstResult = $ops->launch($request, $admin->id, 'Admin One', 'ops001-key-0001');
        $againResult = $ops->launch($request, $admin->id, 'Admin One', 'ops001-key-0001');
        $first = $firstResult->run;
        $again = $againResult->run;

        $this->assertSame($first->id, $again->id);
        $this->assertTrue($firstResult->created);
        $this->assertFalse($againResult->created);
        $this->assertSame(['id' => $admin->id, 'name' => 'Admin One'], $first->request_snapshot['requested_by']);
        $this->assertSame([$stock->id], $first->request_snapshot['stock_ids']);
        $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, $first->jobs()->sole()->status);
        $this->assertDatabaseCount('stox_fundamental_bootstrap_runs', 1);
    }

    public function test_idempotency_key_cannot_be_reused_for_different_scope(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stockA = Stock::query()->create(['symbol' => 'OPS2', 'exchange' => 'NSE', 'name' => 'Ops Two', 'is_active' => true, 'is_benchmark' => false]);
        $stockB = Stock::query()->create(['symbol' => 'OPS3', 'exchange' => 'NSE', 'name' => 'Ops Three', 'is_active' => true, 'is_benchmark' => false]);
        $ops = app(BootstrapOperationsService::class);
        $ops->launch(['scope' => 'stocks', 'stock_ids' => [$stockA->id]], $admin->id, 'Admin One', 'ops001-key-0002');

        $this->expectException(ValidationException::class);
        $ops->launch(['scope' => 'stocks', 'stock_ids' => [$stockB->id]], $admin->id, 'Admin One', 'ops001-key-0002');
    }

    public function test_service_rejects_all_scope_with_ids_and_inactive_stock_targets(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $inactive = Stock::query()->create(['symbol' => 'OPS-INACTIVE', 'exchange' => 'NSE', 'name' => 'Inactive', 'is_active' => false, 'is_benchmark' => false]);
        $ops = app(BootstrapOperationsService::class);

        try {
            $ops->launch(['scope' => 'all', 'stock_ids' => [$inactive->id]], $admin->id, 'Admin', 'ops001-invalid-all-001');
            $this->fail('The all-equities scope must reject supplied stock IDs.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope', $exception->errors());
        }

        try {
            $ops->launch(['scope' => 'stocks', 'stock_ids' => [$inactive->id]], $admin->id, 'Admin', 'ops001-inactive-001');
            $this->fail('Inactive stock IDs must be rejected by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock_ids', $exception->errors());
        }
        $this->assertDatabaseCount('stox_fundamental_bootstrap_runs', 0);
    }

    public function test_forced_rerun_requires_a_reason(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPS4', 'exchange' => 'NSE', 'name' => 'Ops Four', 'is_active' => true, 'is_benchmark' => false]);
        $this->expectException(ValidationException::class);
        app(BootstrapOperationsService::class)->launch([
            'scope' => 'stocks', 'stock_ids' => [$stock->id], 'force' => true,
        ], $admin->id, 'Admin One', 'ops001-key-0003');
    }

    public function test_failed_retry_targets_only_failed_stocks(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $failed = Stock::query()->create(['symbol' => 'OPS5', 'exchange' => 'NSE', 'name' => 'Ops Five', 'is_active' => true, 'is_benchmark' => false]);
        $completed = Stock::query()->create(['symbol' => 'OPS6', 'exchange' => 'NSE', 'name' => 'Ops Six', 'is_active' => true, 'is_benchmark' => false]);
        $run = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'completed']);
        $run->jobs()->create(['stock_id' => $failed->id, 'status' => FundamentalBootstrapJob::STATUS_FAILED]);
        $run->jobs()->create(['stock_id' => $completed->id, 'status' => FundamentalBootstrapJob::STATUS_COMPLETE_GOOD]);

        $retry = app(BootstrapOperationsService::class)->retryFailed($run, $admin->id, 'Admin One', 'ops001-key-0004')->run;

        $this->assertSame([$failed->id], $retry->jobs()->pluck('stock_id')->all());
        $this->assertSame('retry', $retry->trigger);
    }

    public function test_durable_active_run_slot_rejects_a_different_run(): void
    {
        $active = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running']);
        $queued = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'queued']);
        \Illuminate\Support\Facades\DB::table('stox_fund_bootstrap_control')->where('id', 1)->update(['active_run_id' => $active->id]);

        $result = app(FundamentalBootstrapService::class)->process($queued);

        $this->assertSame('another_bootstrap_run_is_active', $result['skipped_reason']);
        $this->assertSame($active->id, (int) \Illuminate\Support\Facades\DB::table('stox_fund_bootstrap_control')->where('id', 1)->value('active_run_id'));
    }

    public function test_unmanaged_running_legacy_run_blocks_ops_without_being_adopted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPS7', 'exchange' => 'NSE', 'name' => 'Ops Seven', 'is_active' => true, 'is_benchmark' => false]);
        $managed = app(BootstrapOperationsService::class)->launch(['scope' => 'stocks', 'stock_ids' => [$stock->id]], $admin->id, 'Admin', 'ops001-managed-0001')->run;
        $legacy = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running']);
        $result = app(FundamentalBootstrapService::class)->process($managed);
        $this->assertSame('another_bootstrap_run_is_active', $result['skipped_reason']);
        $this->assertSame('running', $legacy->fresh()->status);
        $this->assertSame('queued', $managed->fresh()->status);
    }
}
