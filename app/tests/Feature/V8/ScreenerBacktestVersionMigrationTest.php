<?php

namespace Tests\Feature\V8;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScreenerBacktestVersionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Exercise DDL outside RefreshDatabase transactions, in separate tables,
        // without rolling back unrelated application migration history.
        $connection = config('database.connections.'.config('database.default'));
        $connection['prefix'] = 'cache_repair_test_';
        config(['database.connections.cache_repair_test' => $connection]);
        Schema::swap(DB::connection('cache_repair_test')->getSchemaBuilder());

        Schema::create('portfolio_screeners', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('portfolio_screener_versions', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('portfolio_screener_backtest_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screener_id');
            $table->unsignedBigInteger('screener_version_id')->nullable();
            $table->date('as_of_date');
            $table->unique(
                ['screener_id', 'screener_version_id', 'as_of_date'],
                'portfolio_screener_backtest_days_ver_date_uq'
            );
            $table->foreign('screener_id', 'cache_repair_screener_fk')
                ->references('id')->on('portfolio_screeners')->cascadeOnDelete();
            $table->foreign('screener_version_id', 'cache_repair_version_fk')
                ->references('id')->on('portfolio_screener_versions')->nullOnDelete();
        });
    }

    protected function tearDown(): void
    {
        try {
            Schema::dropIfExists('portfolio_screener_backtest_days');
            Schema::dropIfExists('portfolio_screener_versions');
            Schema::dropIfExists('portfolio_screeners');
        } finally {
            DB::purge('cache_repair_test');
            Schema::clearResolvedInstance('db.schema');
            parent::tearDown();
        }
    }

    public function test_repair_handles_legacy_mixed_and_already_correct_indexes_idempotently(): void
    {
        $tableName = 'portfolio_screener_backtest_days';
        $versionKey = ['screener_id', 'screener_version_id', 'as_of_date'];
        $migration = require database_path('migrations/2026_10_02_120000_repair_screener_backtest_day_version_uniqueness.php');
        $foreignKeys = Schema::getForeignKeys($tableName);

        foreach (['legacy', 'mixed', 'correct'] as $state) {
            if ($state !== 'correct') {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unique(['screener_id', 'as_of_date'], 'portfolio_screener_backtest_days_unique');
                });
            }
            if ($state === 'legacy') {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropUnique('portfolio_screener_backtest_days_ver_date_uq');
                });
            }

            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasIndex($tableName, $versionKey, 'unique'), $state);
            $this->assertFalse(Schema::hasIndex($tableName, ['screener_id', 'as_of_date'], 'unique'), $state);
            $this->assertEquals($foreignKeys, Schema::getForeignKeys($tableName), $state);
        }

        $migration->down();
        $this->assertTrue(Schema::hasIndex($tableName, $versionKey, 'unique'));
    }
}
