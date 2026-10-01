<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_screener_backtests')) {
            Schema::table('portfolio_screener_backtests', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_screener_backtests', 'screener_version_id')) {
                    $table->unsignedBigInteger('screener_version_id')->nullable()->after('screener_id');
                    $table->foreign('screener_version_id', 'portfolio_screener_backtests_version_fk')
                        ->references('id')
                        ->on('portfolio_screener_versions')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('portfolio_screener_backtest_days')) {
            Schema::table('portfolio_screener_backtest_days', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_screener_backtest_days', 'screener_version_id')) {
                    $table->unsignedBigInteger('screener_version_id')->nullable()->after('screener_id');
                }
            });

            // MySQL can retain the legacy index when the schema builder cannot
            // resolve its historical name. Inspect the actual index first and
            // drop it explicitly so the version-aware key is authoritative.
            if (DB::getDriverName() === 'mysql') {
                $foreignKeySupportIndex = collect(DB::select(
                    'SHOW INDEX FROM `portfolio_screener_backtest_days` WHERE Key_name = ?',
                    ['portfolio_screener_backtest_days_screener_idx'],
                ));
                if ($foreignKeySupportIndex->isEmpty()) {
                    DB::statement(
                        'ALTER TABLE `portfolio_screener_backtest_days` ADD INDEX `portfolio_screener_backtest_days_screener_idx` (`screener_id`)',
                    );
                }

                $legacyIndex = collect(DB::select(
                    'SHOW INDEX FROM `portfolio_screener_backtest_days` WHERE Key_name = ?',
                    ['portfolio_screener_backtest_days_unique'],
                ));
                if ($legacyIndex->isNotEmpty()) {
                    DB::statement(
                        'ALTER TABLE `portfolio_screener_backtest_days` DROP INDEX `portfolio_screener_backtest_days_unique`',
                    );
                }
            } else {
                try {
                    Schema::table('portfolio_screener_backtest_days', function (Blueprint $table) {
                        $table->dropUnique('portfolio_screener_backtest_days_unique');
                    });
                } catch (Throwable) {
                    // The legacy index was already removed on this driver.
                }
            }

            Schema::table('portfolio_screener_backtest_days', function (Blueprint $table) {
                $table->unique(
                    ['screener_id', 'screener_version_id', 'as_of_date'],
                    'portfolio_screener_backtest_days_ver_date_uq'
                );
                $table->foreign('screener_version_id', 'portfolio_screener_backtest_days_version_fk')
                    ->references('id')
                    ->on('portfolio_screener_versions')
                    ->nullOnDelete();
            });

            $rows = DB::table('portfolio_screener_backtest_days as d')
                ->join('portfolio_screeners as s', 's.id', '=', 'd.screener_id')
                ->leftJoin('portfolio_screener_versions as v', function ($join) {
                    $join->on('v.screener_id', '=', 'd.screener_id')
                        ->on('v.version', '=', 's.artifact_version');
                })
                ->whereNull('d.screener_version_id')
                ->select('d.id', 'v.id as version_id')
                ->get();

            foreach ($rows as $row) {
                if ($row->version_id) {
                    DB::table('portfolio_screener_backtest_days')->where('id', $row->id)->update([
                        'screener_version_id' => $row->version_id,
                    ]);
                }
            }
        }

        if (Schema::hasTable('portfolio_screener_backtest_hits')) {
            Schema::table('portfolio_screener_backtest_hits', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_screener_backtest_hits', 'screener_version_id')) {
                    $table->unsignedBigInteger('screener_version_id')->nullable()->after('screener_id');
                    $table->foreign('screener_version_id', 'portfolio_screener_backtest_hits_version_fk')
                        ->references('id')
                        ->on('portfolio_screener_versions')
                        ->nullOnDelete();
                }
            });

            $hits = DB::table('portfolio_screener_backtest_hits as h')
                ->join('portfolio_screeners as s', 's.id', '=', 'h.screener_id')
                ->leftJoin('portfolio_screener_versions as v', function ($join) {
                    $join->on('v.screener_id', '=', 'h.screener_id')
                        ->on('v.version', '=', 's.artifact_version');
                })
                ->whereNull('h.screener_version_id')
                ->select('h.id', 'v.id as version_id')
                ->get();

            foreach ($hits as $row) {
                if ($row->version_id) {
                    DB::table('portfolio_screener_backtest_hits')->where('id', $row->id)->update([
                        'screener_version_id' => $row->version_id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_screener_backtest_hits') && Schema::hasColumn('portfolio_screener_backtest_hits', 'screener_version_id')) {
            Schema::table('portfolio_screener_backtest_hits', function (Blueprint $table) {
                $table->dropForeign('portfolio_screener_backtest_hits_version_fk');
                $table->dropColumn('screener_version_id');
            });
        }

        if (Schema::hasTable('portfolio_screener_backtest_days') && Schema::hasColumn('portfolio_screener_backtest_days', 'screener_version_id')) {
            Schema::table('portfolio_screener_backtest_days', function (Blueprint $table) {
                $table->dropForeign('portfolio_screener_backtest_days_version_fk');
                $table->dropUnique('portfolio_screener_backtest_days_ver_date_uq');
                $table->dropColumn('screener_version_id');
                $table->unique(['screener_id', 'as_of_date'], 'portfolio_screener_backtest_days_unique');
            });
        }

        if (Schema::hasTable('portfolio_screener_backtests') && Schema::hasColumn('portfolio_screener_backtests', 'screener_version_id')) {
            Schema::table('portfolio_screener_backtests', function (Blueprint $table) {
                $table->dropForeign('portfolio_screener_backtests_version_fk');
                $table->dropColumn('screener_version_id');
            });
        }
    }
};
