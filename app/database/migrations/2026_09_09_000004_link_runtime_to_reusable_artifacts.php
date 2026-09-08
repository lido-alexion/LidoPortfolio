<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
            $table->unsignedBigInteger('reusable_artifact_id')->nullable();
            $table->foreign('reusable_artifact_id', 'tos_strategy_reusable_artifact_fk')->references('id')->on('portfolio_reusable_artifacts')->restrictOnDelete();
        });
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            $table->unsignedBigInteger('reusable_artifact_id')->nullable();
            $table->foreign('reusable_artifact_id', 'screener_reusable_artifact_fk')->references('id')->on('portfolio_reusable_artifacts')->restrictOnDelete();
        });
        foreach ([
            'portfolio_tos_recommendations' => 'tos_rec',
            'portfolio_tos_orders' => 'tos_order',
            'portfolio_tos_order_transactions' => 'tos_fill',
        ] as $tableName => $prefix) {
            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->unsignedBigInteger('reusable_artifact_version_id')->nullable();
                $table->unsignedBigInteger('artifact_binding_revision_id')->nullable();
                $table->foreign('reusable_artifact_version_id', $prefix.'_artifact_version_fk')->references('id')->on('portfolio_reusable_artifact_versions')->restrictOnDelete();
                $table->foreign('artifact_binding_revision_id', $prefix.'_binding_revision_fk')->references('id')->on('portfolio_artifact_binding_revisions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'portfolio_tos_order_transactions' => 'tos_fill',
            'portfolio_tos_orders' => 'tos_order',
            'portfolio_tos_recommendations' => 'tos_rec',
        ] as $tableName => $prefix) {
            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->dropForeign($prefix.'_binding_revision_fk');
                $table->dropForeign($prefix.'_artifact_version_fk');
                $table->dropColumn(['artifact_binding_revision_id', 'reusable_artifact_version_id']);
            });
        }
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            $table->dropForeign('screener_reusable_artifact_fk');
            $table->dropColumn('reusable_artifact_id');
        });
        Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
            $table->dropForeign('tos_strategy_reusable_artifact_fk');
            $table->dropColumn('reusable_artifact_id');
        });
    }
};
