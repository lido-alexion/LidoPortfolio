<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_screener_backtest_days')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $legacyIndex = collect(DB::select(
                'SHOW INDEX FROM `portfolio_screener_backtest_days` WHERE Key_name = ?',
                ['portfolio_screener_backtest_days_unique'],
            ));
            if ($legacyIndex->isNotEmpty()) {
                DB::statement(
                    'ALTER TABLE `portfolio_screener_backtest_days` DROP INDEX `portfolio_screener_backtest_days_unique`',
                );
            }
        }
    }

    public function down(): void
    {
        // The version-aware uniqueness contract must remain in place on rollback
        // of this repair; the preceding migration owns its reversible schema.
    }
};
