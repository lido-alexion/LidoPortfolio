<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SD-030: Strategies consume Screeners — eligibility junction + factory screener keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_screeners')) {
            Schema::table('portfolio_screeners', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_screeners', 'is_factory')) {
                    $table->boolean('is_factory')->default(false)->after('is_shared');
                }
                if (! Schema::hasColumn('portfolio_screeners', 'factory_key')) {
                    $table->string('factory_key', 64)->nullable()->after('is_factory');
                }
            });
            try {
                Schema::table('portfolio_screeners', function (Blueprint $table) {
                    $table->unique(['profile_id', 'factory_key'], 'scr_profile_factory_uq');
                });
            } catch (\Throwable) {
            }
        }

        if (! Schema::hasTable('portfolio_tos_strategy_screeners')) {
            Schema::create('portfolio_tos_strategy_screeners', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('strategy_version_id');
                $table->unsignedBigInteger('screener_id');
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('priority')->default(1);
                $table->unsignedInteger('display_order')->default(0);
                $table->timestamps();

                $table->unique(['strategy_version_id', 'screener_id'], 'tos_strat_scr_unique');
                $table->index(['screener_id'], 'tos_strat_scr_screener_idx');
                $table->foreign('strategy_version_id')
                    ->references('id')
                    ->on('portfolio_tos_strategy_versions')
                    ->cascadeOnDelete();
                $table->foreign('screener_id')
                    ->references('id')
                    ->on('portfolio_screeners')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_strategy_screeners');

        if (Schema::hasTable('portfolio_screeners')) {
            Schema::table('portfolio_screeners', function (Blueprint $table) {
                try {
                    $table->dropUnique('scr_profile_factory_uq');
                } catch (\Throwable) {
                }
                foreach (['factory_key', 'is_factory'] as $col) {
                    if (Schema::hasColumn('portfolio_screeners', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
