<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_screener_versions')) {
            Schema::table('portfolio_screener_versions', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_screener_versions', 'scope')) {
                    $table->string('scope', 32)->nullable()->after('definition_json');
                }
                if (! Schema::hasColumn('portfolio_screener_versions', 'watchlist_id')) {
                    $table->unsignedBigInteger('watchlist_id')->nullable()->after('scope');
                }
                if (! Schema::hasColumn('portfolio_screener_versions', 'index_symbol')) {
                    $table->string('index_symbol', 64)->nullable()->after('watchlist_id');
                }
            });

            $rows = DB::table('portfolio_screener_versions as v')
                ->join('portfolio_screeners as s', 's.id', '=', 'v.screener_id')
                ->whereNull('v.scope')
                ->whereColumn('v.version', 's.artifact_version')
                ->select('v.id', 's.scope', 's.watchlist_id', 's.index_symbol')
                ->get();

            foreach ($rows as $row) {
                DB::table('portfolio_screener_versions')->where('id', $row->id)->update([
                    'scope' => $row->scope,
                    'watchlist_id' => $row->watchlist_id,
                    'index_symbol' => $row->index_symbol,
                ]);
            }
        }

        if (Schema::hasTable('portfolio_screener_runs')) {
            Schema::table('portfolio_screener_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_screener_runs', 'screener_version_id')) {
                    $table->unsignedBigInteger('screener_version_id')->nullable()->after('screener_id');
                    $table->foreign('screener_version_id', 'portfolio_screener_runs_version_fk')
                        ->references('id')
                        ->on('portfolio_screener_versions')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('portfolio_tos_strategy_screeners')) {
            Schema::table('portfolio_tos_strategy_screeners', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_tos_strategy_screeners', 'screener_version_id')) {
                    $table->unsignedBigInteger('screener_version_id')->nullable()->after('screener_id');
                    $table->foreign('screener_version_id', 'portfolio_tos_strategy_screeners_version_fk')
                        ->references('id')
                        ->on('portfolio_screener_versions')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_screener_runs') && Schema::hasColumn('portfolio_screener_runs', 'screener_version_id')) {
            Schema::table('portfolio_screener_runs', function (Blueprint $table) {
                $table->dropForeign('portfolio_screener_runs_version_fk');
                $table->dropColumn('screener_version_id');
            });
        }

        if (Schema::hasTable('portfolio_tos_strategy_screeners') && Schema::hasColumn('portfolio_tos_strategy_screeners', 'screener_version_id')) {
            Schema::table('portfolio_tos_strategy_screeners', function (Blueprint $table) {
                $table->dropForeign('portfolio_tos_strategy_screeners_version_fk');
                $table->dropColumn('screener_version_id');
            });
        }

        if (Schema::hasTable('portfolio_screener_versions')) {
            Schema::table('portfolio_screener_versions', function (Blueprint $table) {
                foreach (['index_symbol', 'watchlist_id', 'scope'] as $column) {
                    if (Schema::hasColumn('portfolio_screener_versions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
