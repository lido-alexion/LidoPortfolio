<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['portfolio_screener_backtests', 'portfolio_backtest_runs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $prefix = $tableName === 'portfolio_screener_backtests' ? 'screener_backtest' : 'strategy_backtest';
                $table->unsignedBigInteger('reusable_artifact_version_id')->nullable();
                $table->unsignedBigInteger('artifact_binding_revision_id')->nullable();
                $table->foreign('reusable_artifact_version_id', $prefix.'_artifact_version_fk')
                    ->references('id')->on('portfolio_reusable_artifact_versions')->restrictOnDelete();
                $table->foreign('artifact_binding_revision_id', $prefix.'_binding_revision_fk')
                    ->references('id')->on('portfolio_artifact_binding_revisions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['portfolio_screener_backtests', 'portfolio_backtest_runs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $prefix = $tableName === 'portfolio_screener_backtests' ? 'screener_backtest' : 'strategy_backtest';
                $table->dropForeign($prefix.'_artifact_version_fk');
                $table->dropForeign($prefix.'_binding_revision_fk');
                $table->dropColumn(['artifact_binding_revision_id', 'reusable_artifact_version_id']);
            });
        }
    }
};
