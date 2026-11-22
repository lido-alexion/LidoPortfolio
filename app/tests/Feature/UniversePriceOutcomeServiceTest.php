<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\UniversePrice\UniversePriceOutcomeService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UniversePriceOutcomeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 12:00:00');
        TradingCalendar::clearHolidayCache();
        config([
            'portfolio.universe_price_sync.no_data_skip_after_sessions' => 5,
            'portfolio.universe_price_sync.no_data_reprobe_after_sessions' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    private function stock(string $symbol = 'NODATA'): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => $symbol, 'name' => $symbol, 'exchange' => 'NSE', 'series' => 'EQ',
            'isin' => 'INE001A01010', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $stock->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();
        return $stock;
    }

    private function validFiles(array $dates): array
    {
        $files = $expected = [];
        foreach ($dates as $date) {
            $expected[] = ['exchange' => 'NSE', 'date' => $date];
            $files[] = [
                'exchange' => 'NSE', 'date' => $date, 'status' => 'applied',
                'coverage_hash' => hash('sha256', ''),
                'source_revision_sha256' => hash('sha256', 'bhavcopy-'.$date),
            ];
        }
        return [['expected_files' => $expected, 'files' => $files], $dates];
    }

    public function test_valid_file_absence_is_deduplicated_no_data_and_temporary_skip_reprobes(): void
    {
        $stock = $this->stock();
        $dates = ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'];
        [$bulk] = $this->validFiles($dates);
        $stats = ['bulk' => $bulk];
        $service = app(UniversePriceOutcomeService::class);

        $service->recordRun($stats, collect([$stock]), 'daily-run-1');
        $service->recordRun($stats, collect([$stock]), 'daily-run-replay');

        $this->assertDatabaseCount('portfolio_universe_price_session_outcomes', 5);
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-23', 'outcome' => 'no_data',
            'reason' => 'absent_from_validated_exchange_file',
        ]);
        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 5,
            'last_session_date' => '2026-10-23', 'skipped_until_session' => '2026-11-02',
        ]);
        $this->assertSame(5, $service->summary()['outcomes']['no_data']);
        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $stock->id]);

        [$cooldown] = $this->validFiles(['2026-10-26']);
        $service->recordRun(['bulk' => $cooldown], collect([$stock]), 'cooldown-session');
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-26', 'outcome' => 'skipped',
            'reason' => 'temporary_no_data_reprobe_cooldown',
        ]);
        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 5,
        ]);

        foreach (['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $date) {
            [$cooldown] = $this->validFiles([$date]);
            $service->recordRun(['bulk' => $cooldown], collect([$stock]), 'cooldown-'.$date);
            $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
                'stock_id' => $stock->id, 'session_date' => $date, 'outcome' => 'skipped',
            ]);
        }

        [$reprobe] = $this->validFiles(['2026-11-02']);
        $service->recordRun(['bulk' => $reprobe], collect([$stock]), 'automatic-reprobe');
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-11-02', 'outcome' => 'no_data',
        ]);
        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 1,
            'skipped_until_session' => null,
        ]);
    }

    public function test_exchange_failure_and_identity_failure_are_not_downgraded_to_no_data(): void
    {
        $stock = $this->stock();
        $date = '2026-10-26';
        $stats = ['bulk' => [
            'expected_files' => [['exchange' => 'NSE', 'date' => $date]],
            'files' => [[
                'exchange' => 'NSE', 'date' => $date, 'status' => 'retry_deferred',
                'failure_stage' => 'exchange_fetch', 'retry_attempt' => 3,
                'retry_after' => '2026-10-26T21:00:00+05:30',
            ]],
        ]];

        app(UniversePriceOutcomeService::class)->recordRun($stats, collect([$stock]), 'failed-run');

        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => $date, 'outcome' => 'provider_error',
            'failure_stage' => 'exchange_fetch', 'attempt_count' => 3,
        ]);

        $stats['bulk']['files'][0]['status'] = 'identity_conflict';
        $stats['bulk']['files'][0]['failure_stage'] = 'identity_validation';
        app(UniversePriceOutcomeService::class)->recordRun($stats, collect([$stock]), 'identity-run');
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => $date, 'outcome' => 'systemic_error',
            'failure_stage' => 'identity_validation',
        ]);

        $stats['bulk']['files'][0]['status'] = 'failed';
        $stats['bulk']['files'][0]['failure_stage'] = 'source_validation';
        app(UniversePriceOutcomeService::class)->recordRun($stats, collect([$stock]), 'malformed-run');
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => $date, 'outcome' => 'systemic_error',
            'failure_stage' => 'source_validation',
        ]);
    }

    public function test_sessions_before_security_entered_stock_master_are_not_counted_as_no_data(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'RECENT', 'name' => 'RECENT', 'exchange' => 'NSE', 'series' => 'EQ',
            'isin' => 'INE001A01010', 'is_active' => true, 'is_benchmark' => false,
        ]);
        [$bulk] = $this->validFiles(['2026-10-23']);

        app(UniversePriceOutcomeService::class)->recordRun(['bulk' => $bulk], collect([$stock]), 'before-master');

        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-23',
            'outcome' => 'not_expected', 'reason' => 'before_stock_master_record',
        ]);
        $this->assertDatabaseMissing('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-23', 'outcome' => 'no_data',
        ]);
    }

    public function test_stock_master_creation_instant_is_compared_using_exchange_calendar_date(): void
    {
        $stock = $this->stock();
        $stock->forceFill(['created_at' => Carbon::parse('2026-10-26 20:00:00', 'UTC')])->save();
        [$bulk] = $this->validFiles(['2026-10-26', '2026-10-27']);

        app(UniversePriceOutcomeService::class)->recordRun(['bulk' => $bulk], collect([$stock]), 'market-date-master');

        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-26',
            'outcome' => 'not_expected', 'reason' => 'before_stock_master_record',
        ]);
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-27', 'outcome' => 'no_data',
        ]);
    }

    public function test_historical_backfill_recovery_marks_bar_covered_and_clears_latest_skip_state(): void
    {
        $stock = $this->stock();
        $date = '2026-10-23';
        [$bulk] = $this->validFiles([$date]);
        app(UniversePriceOutcomeService::class)->recordRun(['bulk' => $bulk], collect([$stock]), 'daily-run');
        DB::table('portfolio_universe_price_symbol_states')->where('stock_id', $stock->id)->update([
            'consecutive_no_data_sessions' => 5, 'skipped_until_session' => '2026-10-30',
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => $date, 'open_price' => 10, 'high_price' => 11,
            'low_price' => 9, 'close_price' => 10, 'adjusted_close_price' => 10,
            'volume' => 100, 'provider_source' => 'manual', 'data_source' => 'manual',
        ]);

        app(UniversePriceOutcomeService::class)->markRecoveredByHistory($stock, [$date]);

        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => $date, 'outcome' => 'covered',
            'reason' => 'recovered_by_historical_backfill', 'resolved_by_source' => 'manual',
        ]);
        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 0,
            'last_outcome' => 'covered', 'skipped_until_session' => null,
        ]);
    }

    public function test_older_replay_does_not_mutate_forward_state_but_latest_recovery_does(): void
    {
        $stock = $this->stock();
        DB::table('portfolio_universe_price_symbol_states')->insert([
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 4,
            'last_session_date' => '2026-10-23', 'last_outcome' => 'no_data',
            'skipped_until_session' => '2026-10-30', 'updated_at' => now(),
        ]);

        [$older] = $this->validFiles(['2026-10-20']);
        app(UniversePriceOutcomeService::class)->recordRun(['bulk' => $older], collect([$stock]), 'historical-replay');
        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 4,
            'last_session_date' => '2026-10-23', 'last_outcome' => 'no_data',
            'skipped_until_session' => '2026-10-30',
        ]);

        $stockPrice = StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-10-23', 'open_price' => 10, 'high_price' => 11,
            'low_price' => 9, 'close_price' => 10, 'adjusted_close_price' => 10,
            'volume' => 100, 'provider_source' => 'nse', 'data_source' => 'nse',
        ]);
        [$latest] = $this->validFiles(['2026-10-23']);
        app(UniversePriceOutcomeService::class)->recordRun(['bulk' => $latest], collect([$stock]), 'latest-recovery');
        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 0,
            'last_session_date' => '2026-10-23', 'last_outcome' => 'covered', 'skipped_until_session' => null,
        ]);
    }

    public function test_manual_reprobe_is_admin_audited_and_resets_only_no_data_state(): void
    {
        $stock = $this->stock();
        DB::table('portfolio_universe_price_symbol_states')->insert([
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 8,
            'last_session_date' => '2026-10-23', 'last_outcome' => 'no_data',
            'skipped_until_session' => '2026-10-30', 'updated_at' => now(),
        ]);

        $service = app(UniversePriceOutcomeService::class);
        $lock = Cache::store('database')->lock('portfolio-universe-price-sync', 60);
        $this->assertTrue($lock->get());
        $this->assertFalse($service->resetForReprobe($stock, null));
        $lock->release();
        $this->assertTrue($service->resetForReprobe($stock, null));

        $this->assertDatabaseHas('portfolio_universe_price_symbol_states', [
            'stock_id' => $stock->id, 'consecutive_no_data_sessions' => 0,
            'skipped_until_session' => null,
        ]);
        $this->assertDatabaseHas('portfolio_universe_price_admin_events', [
            'stock_id' => $stock->id, 'action' => 'manual_reprobe',
        ]);
    }

    public function test_coverage_collapse_needs_two_sessions_against_stable_baseline_and_two_clean_sessions_recover(): void
    {
        $stocks = collect(range(1, 10))->map(fn ($n) => $this->stock('BREADTH'.$n));
        $rows = [];
        $sessions = [
            '2026-10-12' => 1, '2026-10-13' => 1, '2026-10-14' => 1,
            '2026-10-15' => 1, '2026-10-16' => 1, '2026-10-19' => 8, '2026-10-20' => 8,
        ];
        foreach ($sessions as $date => $noDataCount) {
            foreach ($stocks as $index => $stock) {
                $rows[] = [
                    'stock_id' => $stock->id, 'session_date' => $date, 'exchange' => 'NSE',
                    'outcome' => $index < $noDataCount ? 'no_data' : 'covered',
                    'attempt_count' => 0, 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        DB::table('portfolio_universe_price_session_outcomes')->insert($rows);
        $service = app(UniversePriceOutcomeService::class);
        $this->assertSame('NSE', $service->systemicCoverageCollapse()['exchange']);
        $this->assertSame(80, $service->systemicCoverageCollapse()['no_data_share_percent']);

        foreach (['2026-10-21', '2026-10-22'] as $date) {
            foreach ($stocks as $stock) {
                DB::table('portfolio_universe_price_session_outcomes')->insert([
                    'stock_id' => $stock->id, 'session_date' => $date, 'exchange' => 'NSE',
                    'outcome' => 'covered', 'attempt_count' => 0, 'observed_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->assertNull($service->systemicCoverageCollapse());
    }

    public function test_coverage_collapse_does_not_treat_separated_observations_as_consecutive_sessions(): void
    {
        $stocks = collect(range(1, 10))->map(fn ($n) => $this->stock('GAP'.$n));
        $rows = [];
        $sessions = [
            '2026-10-12' => 1, '2026-10-13' => 1, '2026-10-14' => 1,
            '2026-10-15' => 1, '2026-10-16' => 1, '2026-10-20' => 8, '2026-10-22' => 8,
        ];
        foreach ($sessions as $date => $noDataCount) {
            foreach ($stocks as $index => $stock) {
                $rows[] = [
                    'stock_id' => $stock->id, 'session_date' => $date, 'exchange' => 'NSE',
                    'outcome' => $index < $noDataCount ? 'no_data' : 'covered',
                    'attempt_count' => 0, 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        DB::table('portfolio_universe_price_session_outcomes')->insert($rows);

        $this->assertNull(app(UniversePriceOutcomeService::class)->systemicCoverageCollapse());
    }
}
