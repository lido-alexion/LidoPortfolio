<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SD-029: Factory strategy protection + lineage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_strategies')) {
            return;
        }

        Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_tos_strategies', 'is_factory')) {
                $table->boolean('is_factory')->default(false)->after('status');
            }
            if (! Schema::hasColumn('portfolio_tos_strategies', 'factory_key')) {
                $table->string('factory_key', 64)->nullable()->after('is_factory');
            }
            if (! Schema::hasColumn('portfolio_tos_strategies', 'duplicated_from_id')) {
                $table->unsignedBigInteger('duplicated_from_id')->nullable()->after('factory_key');
            }
        });

        if (Schema::hasTable('portfolio_tos_strategy_versions')
            && ! Schema::hasColumn('portfolio_tos_strategy_versions', 'version_label')) {
            Schema::table('portfolio_tos_strategy_versions', function (Blueprint $table) {
                $table->string('version_label', 32)->nullable()->after('version');
            });
        }

        // Unique factory key per profile (one Momentum factory).
        // Use short index name for MySQL limits.
        try {
            Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
                $table->unique(['profile_id', 'factory_key'], 'tos_strat_profile_factory_uq');
            });
        } catch (\Throwable) {
            // Index may already exist.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_tos_strategies')) {
            return;
        }

        Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
            try {
                $table->dropUnique('tos_strat_profile_factory_uq');
            } catch (\Throwable) {
            }
            foreach (['duplicated_from_id', 'factory_key', 'is_factory'] as $col) {
                if (Schema::hasColumn('portfolio_tos_strategies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('portfolio_tos_strategy_versions')
            && Schema::hasColumn('portfolio_tos_strategy_versions', 'version_label')) {
            Schema::table('portfolio_tos_strategy_versions', function (Blueprint $table) {
                $table->dropColumn('version_label');
            });
        }
    }
};
