<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\BseEquityMasterService;
use App\Services\DualListedNseRepairService;
use App\Services\PriceFetchService;
use App\Services\ProviderResolverService;
use App\Services\StockMasterSyncService;
use App\Services\SyncLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BseIdentityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private array $audit = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function stock(string $symbol = 'AAA', string $code = '500001'): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol, 'exchange' => 'BSE', 'bse_scrip_code' => $code,
            'name' => $symbol.' original', 'is_active' => true, 'is_benchmark' => false,
            'yahoo_symbol' => $symbol.'.BO',
        ]);
    }

    private function service(array $rows, ?callable $onLog = null, ?string $url = null, ?callable $onComplete = null, ?callable $mutateStoredChanges = null, ?callable $onSource = null): StockMasterSyncService
    {
        $bse = Mockery::mock(BseEquityMasterService::class);
        $bse->shouldReceive('listApiUrl')->andReturn($url ?? BseEquityMasterService::DEFAULT_LIST_API_URL);
        $bse->shouldReceive('fetchFromApi')->andReturnUsing(function () use ($rows, $onSource): array {
            if ($onSource) {
                $onSource();
            }

            return $rows;
        });
        $bse->shouldNotReceive('fetchEquityRows');
        $logs = Mockery::mock(SyncLogService::class);
        $logs->shouldReceive('isEnabled')->andReturn(true);
        $logs->shouldReceive('beginRun')->andReturnUsing(function (string $job): string {
            $id = (string) \Illuminate\Support\Str::uuid();
            \App\Models\SyncRun::query()->create(['id' => $id, 'job_name' => $job, 'status' => 'running', 'started_at' => now()]);

            return $id;
        });
        $logs->shouldReceive('completeRun')->andReturnUsing(function ($run, $status, $stats = [], $summary = null) use ($onComplete): void {
            if ($onComplete && $onComplete($status) === false) {
                return;
            }
            \App\Models\SyncRun::query()->whereKey($run)->update([
                'status' => $status, 'stocks_processed' => $stats['processed'] ?? null,
                'skipped' => $stats['skipped'] ?? null, 'finished_at' => now(), 'summary' => $summary,
            ]);
        });
        $logs->shouldReceive('log')->andReturnUsing(function ($run, $job, $level, $message, $context) use ($onLog, $mutateStoredChanges): void {
            $this->audit[] = $context;
            if ($onLog && $onLog($message) === false) {
                return;
            }
            if ($message === 'BSE identity-only changes' && $mutateStoredChanges) {
                $context['changes'] = $mutateStoredChanges($context['changes']);
            }
            \App\Models\SyncLog::query()->create([
                'run_id' => $run, 'job_name' => $job, 'level' => $level, 'message' => $message,
                'context' => $context, 'logged_at' => now(),
            ]);
        });
        $resolver = Mockery::mock(ProviderResolverService::class);
        $resolver->shouldNotReceive('applyProviderSymbols');
        $prices = Mockery::mock(PriceFetchService::class);
        $prices->shouldNotReceive('syncStock');
        $repair = Mockery::mock(DualListedNseRepairService::class);
        $repair->shouldNotReceive('repair');

        return new StockMasterSyncService($resolver, $logs, $prices, $bse, $repair);
    }

    public function test_preview_then_exact_apply_preserves_prices_holdings_flags_and_identity(): void
    {
        $stock = $this->stock();
        $price = StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-10-23',
            'open_price' => 10, 'high_price' => 12, 'low_price' => 9,
            'close_price' => 11, 'adjusted_close_price' => 10.5, 'volume' => 123,
            'provider_source' => 'manual_repair', 'created_at' => now(),
        ]);
        $profile = $this->defaultPortfolioFor(User::factory()->create());
        $holding = Holding::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id,
            'quantity' => 5, 'avg_buy_price' => 20, 'invested_amount' => 100,
            'target_amount' => 0, 'filled_amount' => 100, 'total_fees' => 0,
            'realized_profit' => 0, 'updated_at' => now(),
        ]);
        $stockBefore = $stock->fresh()->getAttributes();
        $priceBefore = $price->fresh()->getAttributes();
        $holdingBefore = $holding->fresh()->getAttributes();
        $service = $this->service([['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010']]);

        $preview = $service->reconcileMissingBseIsins();
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['candidate_count']);
        $this->assertSame([], $this->audit);
        $this->assertSame($stockBefore, $stock->fresh()->getAttributes());

        $applied = $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
        $this->assertSame(1, $applied['applied_count']);
        $after = $stock->fresh()->getAttributes();
        $this->assertSame('INE000A01010', $after['isin']);
        unset($stockBefore['isin'], $stockBefore['updated_at'], $after['isin'], $after['updated_at']);
        $this->assertSame($stockBefore, $after);
        $this->assertSame($priceBefore, $price->fresh()->getAttributes());
        $this->assertSame($holdingBefore, $holding->fresh()->getAttributes());
        $this->assertSame($preview['plan_sha256'], $this->audit[0]['plan_sha256']);
        $this->assertSame('INE000A01010', $this->audit[1]['changes'][0]['new_isin']);
        $repeat = $service->reconcileMissingBseIsins();
        $this->assertSame(0, $repeat['candidate_count']);
        $this->assertSame(1, $repeat['reason_counts']['already_identified']);
    }

    public function test_changed_master_or_source_requires_a_new_preview(): void
    {
        $stock = $this->stock();
        $rows = [['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010']];
        $service = $this->service($rows);
        $preview = $service->reconcileMissingBseIsins();
        $stock->update(['bse_scrip_code' => '500002']);
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('A stale plan must not apply.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('changed since preview', $error->getMessage());
        }
        $this->assertNull($stock->fresh()->isin);
        $this->assertSame([], $this->audit);
    }

    public function test_audit_failure_rolls_back_every_identity_change(): void
    {
        $a = $this->stock();
        $b = $this->stock('BBB', '500002');
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
            ['symbol' => 'BBB', 'scrip_code' => '500002', 'isin' => 'INE001A01010'],
        ], function (string $message): void {
            if ($message === 'BSE identity-only changes') {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $preview = $service->reconcileMissingBseIsins();
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('Apply must roll back if its audit cannot be persisted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertNull($a->fresh()->isin);
        $this->assertNull($b->fresh()->isin);
    }

    public function test_busy_universe_lock_remains_owned_and_no_apply_occurs(): void
    {
        $stock = $this->stock();
        $service = $this->service([['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010']]);
        $preview = $service->reconcileMissingBseIsins();
        $lock = Cache::store('database')->lock('portfolio-universe-price-sync', 60);
        $this->assertTrue($lock->get());
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('Busy writer must defer reconciliation.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('owner is busy', $error->getMessage());
            $this->assertFalse(Cache::store('database')->lock('portfolio-universe-price-sync', 60)->get());
            $this->assertNull($stock->fresh()->isin);
        } finally {
            $lock->release();
        }
    }

    public function test_command_defaults_to_preview_and_rejects_unreviewed_apply(): void
    {
        $stock = $this->stock();
        $this->app->instance(StockMasterSyncService::class,
            $this->service([['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010']]));
        $this->artisan('stocks:reconcile-bse-identities')->assertSuccessful();
        $this->assertNull($stock->fresh()->isin);
        $this->artisan('stocks:reconcile-bse-identities', ['--apply' => true])->assertFailed();
        $this->assertNull($stock->fresh()->isin);
    }

    public function test_real_owner_adapter_and_sync_log_persist_the_applied_identity_evidence(): void
    {
        $stock = $this->stock();
        config(['portfolio.stock_master.bse_list_api_url' => null]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            BseEquityMasterService::DEFAULT_LIST_API_URL => \Illuminate\Support\Facades\Http::response([[
                'SCRIP_CD' => '500001', 'scrip_id' => 'AAA', 'Scrip_Name' => 'AAA Official',
                'ISIN_NUMBER' => 'INE000A01010', 'Status' => 'Active',
            ]]),
        ]);
        $service = app(StockMasterSyncService::class);
        $preview = $service->reconcileMissingBseIsins();
        $this->assertDatabaseCount('portfolio_sync_runs', 0);
        $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
        $this->assertSame('INE000A01010', $stock->fresh()->isin);
        $run = \App\Models\SyncRun::query()->where('job_name', 'bse-identity-reconciliation')->sole();
        $this->assertSame('success', $run->status);
        $change = \App\Models\SyncLog::query()->where('run_id', $run->id)
            ->where('message', 'BSE identity-only changes')->sole();
        $this->assertSame($preview['plan_sha256'], $change->context['plan_sha256']);
        $this->assertSame($stock->id, $change->context['changes'][0]['stock_id']);
        $this->assertSame('INE000A01010', $change->context['changes'][0]['new_isin']);
        \Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    public function test_a_source_revision_or_nonofficial_endpoint_cannot_apply_an_old_plan(): void
    {
        $stock = $this->stock();
        $preview = $this->service([['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010']])
            ->reconcileMissingBseIsins();
        $revised = $this->service([['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE001A01010']]);
        try {
            $revised->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('A revised source must produce a new reviewed plan.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('changed since preview', $error->getMessage());
        }
        $untrusted = $this->service([], null, 'https://example.test/BseIndiaAPI/api/ListofScripData/w?segment=Equity&status=Active');
        try {
            $untrusted->reconcileMissingBseIsins();
            $this->fail('Only the approved official API may supply identity updates.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('official active-equity API', $error->getMessage());
        }
        $this->assertNull($stock->fresh()->isin);
    }

    public function test_new_identity_or_admin_override_committed_after_preview_is_preserved(): void
    {
        foreach (['isin' => 'INE999A01010', 'admin_deactivated' => true] as $field => $value) {
            $symbol = $field === 'isin' ? 'AAA' : 'BBB';
            $code = $field === 'isin' ? '500001' : '500002';
            $stock = $this->stock($symbol, $code);
            $service = $this->service([['symbol' => $symbol, 'scrip_code' => $code, 'isin' => 'INE000A01010']]);
            $preview = $service->reconcileMissingBseIsins();
            $stock->forceFill([$field => $value])->save();
            $committed = $stock->fresh()->getAttributes();
            try {
                $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
                $this->fail('A concurrent identity/eligibility correction must invalidate the plan.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('changed since preview', $error->getMessage());
            }
            $this->assertSame($committed, $stock->fresh()->getAttributes());
        }
    }

    public function test_silently_disabled_change_audit_rolls_back_metadata(): void
    {
        $stock = $this->stock();
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ], fn (string $message) => $message !== 'BSE identity-only changes');
        $preview = $service->reconcileMissingBseIsins();
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('A silent no-op audit must not commit metadata.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('change audit was not persisted', $error->getMessage());
        }
        $this->assertNull($stock->fresh()->isin);
        $this->assertDatabaseCount('portfolio_sync_logs', 0);
        $this->assertSame('failed', \App\Models\SyncRun::query()->sole()->status);
    }

    public function test_silently_missing_success_completion_rolls_back_metadata_and_audit(): void
    {
        $stock = $this->stock();
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ], null, null, fn (string $status) => $status !== 'success');
        $preview = $service->reconcileMissingBseIsins();
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('A no-op completion must not commit the stock changes.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('success audit was not persisted', $error->getMessage());
        }
        $this->assertNull($stock->fresh()->isin);
        $this->assertDatabaseCount('portfolio_sync_logs', 0);
        $this->assertSame('failed', \App\Models\SyncRun::query()->sole()->status);
    }

    public function test_failed_failure_status_recording_preserves_the_original_completion_exception(): void
    {
        $stock = $this->stock();
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ], null, null, function (string $status): void {
            throw new \RuntimeException($status === 'success' ? 'Original completion unavailable' : 'Failure recorder unavailable');
        });
        $preview = $service->reconcileMissingBseIsins();
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('Completion persistence failure must roll back.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Original completion unavailable', $error->getMessage());
        }
        $this->assertNull($stock->fresh()->isin);
        $this->assertDatabaseCount('portfolio_sync_logs', 0);
    }

    public function test_persisted_audit_object_key_reordering_does_not_reject_a_correct_plan(): void
    {
        $stock = $this->stock();
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ], null, null, null, fn (array $changes) => array_map(
            fn (array $change) => array_reverse($change, true), $changes,
        ));
        $preview = $service->reconcileMissingBseIsins();
        $applied = $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
        $this->assertSame(1, $applied['applied_count']);
        $this->assertSame('INE000A01010', $stock->fresh()->isin);
        $this->assertSame('success', \App\Models\SyncRun::query()->sole()->status);
    }

    public function test_audit_list_order_keys_and_scalar_types_remain_strict(): void
    {
        $a = $this->stock();
        $b = $this->stock('BBB', '500002');
        $rows = [
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
            ['symbol' => 'BBB', 'scrip_code' => '500002', 'isin' => 'INE001A01010'],
        ];
        foreach (['list_order', 'extra_key', 'missing_key', 'scalar_type'] as $fault) {
            $service = $this->service($rows, null, null, null, function (array $changes) use ($fault): array {
                switch ($fault) {
                    case 'list_order': return array_reverse($changes);
                    case 'extra_key': $changes[0]['unexpected'] = true;
                        break;
                    case 'missing_key': unset($changes[0]['old_isin']);
                        break;
                    case 'scalar_type': $changes[0]['stock_id'] = (string) $changes[0]['stock_id'];
                        break;
                }

                return $changes;
            });
            $preview = $service->reconcileMissingBseIsins();
            try {
                $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
                $this->fail('Audit mismatch must refuse '.$fault);
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('change audit was not persisted', $error->getMessage(), $fault);
            }
            $this->assertNull($a->fresh()->isin);
            $this->assertNull($b->fresh()->isin);
        }
        $this->assertDatabaseCount('portfolio_sync_logs', 0);
    }

    public function test_durable_identity_ledger_survives_sync_log_pruning_and_is_append_only(): void
    {
        $stock = $this->stock();
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ]);
        $preview = $service->reconcileMissingBseIsins();
        $this->assertDatabaseCount('portfolio_bse_identity_audits', 0);
        $applied = $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
        $audit = \App\Models\BseIdentityReconciliationAudit::query()->findOrFail($applied['audit_id']);
        $this->assertSame($preview['plan_sha256'], $audit->plan_sha256);
        $this->assertSame($preview['plan_sha256'], (new \App\Services\BseIdentityReconciliationPlan)->hashPlan($audit->plan_json));
        $this->assertSame($stock->id, $audit->plan_json['targets'][0]['stock_id']);
        $this->assertSame(1, $audit->result_json['applied_count']);
        \App\Models\SyncRun::query()->delete();
        $this->assertDatabaseCount('portfolio_sync_logs', 0);
        $this->assertSame($preview['plan_sha256'], $audit->fresh()->plan_sha256);
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $audit->update(['result_json' => []]) : $audit->delete();
                $this->fail('Durable owner evidence must remain append-only.');
            } catch (\LogicException $error) {
                $this->assertStringContainsString('append-only', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('portfolio_bse_identity_audits', 1);
        $this->assertSame(1, $audit->fresh()->result_json['applied_count']);
    }

    public function test_lease_expiry_and_apply_budget_expiry_roll_back_before_commit(): void
    {
        $stock = $this->stock();
        $rows = [['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010']];
        foreach ([121 => 'budget exhausted', 301 => 'lease was lost or expired'] as $elapsed => $message) {
            $service = $this->service($rows, function (string $event) use ($elapsed): void {
                if ($event === 'BSE identity-only changes') {
                    \Carbon\Carbon::setTestNow(now()->addSeconds($elapsed));
                }
            });
            $preview = $service->reconcileMissingBseIsins();
            try {
                $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
                $this->fail('A delayed metadata owner must not commit.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            } finally {
                \Carbon\Carbon::setTestNow();
            }
            $this->assertNull($stock->fresh()->isin);
            $this->assertDatabaseCount('portfolio_sync_logs', 0);
            $this->assertDatabaseCount('portfolio_bse_identity_audits', 0);
        }
    }

    public function test_a_changed_lease_owner_refuses_the_apply(): void
    {
        $stock = $this->stock();
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ], function (string $event): void {
            if ($event === 'Applying reviewed BSE identity-only plan') {
                $key = Cache::store('database')->getStore()->getPrefix().'portfolio-universe-price-sync';
                \Illuminate\Support\Facades\DB::table(config('cache.stores.database.lock_table') ?: 'cache_locks')
                    ->where('key', $key)->update(['owner' => 'changed-owner']);
            }
        });
        $preview = $service->reconcileMissingBseIsins();
        try {
            $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
            $this->fail('Lost ownership must refuse metadata writes.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('lease was lost or expired', $error->getMessage());
        }
        $this->assertNull($stock->fresh()->isin);
        $this->assertDatabaseCount('portfolio_bse_identity_audits', 0);
    }

    public function test_source_acquisition_does_not_hold_the_universe_writer_lease(): void
    {
        $stock = $this->stock();
        $sourceWasFree = 0;
        $service = $this->service([
            ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'],
        ], null, null, null, null, function () use (&$sourceWasFree): void {
            $lock = Cache::store('database')->lock('portfolio-universe-price-sync', 60);
            $this->assertTrue($lock->get());
            $sourceWasFree++;
            $lock->release();
        });
        $preview = $service->reconcileMissingBseIsins();
        $service->reconcileMissingBseIsins(true, $preview['plan_sha256']);
        $this->assertSame(2, $sourceWasFree);
        $this->assertSame('INE000A01010', $stock->fresh()->isin);
    }
}
