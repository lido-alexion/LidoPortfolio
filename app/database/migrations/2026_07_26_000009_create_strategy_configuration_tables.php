<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SD-027: Strategy Configuration — versioned JSON config driving Recommendation scoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_strategies')) {
            Schema::create('portfolio_tos_strategies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('profile_id');
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('status', 32)->default('draft'); // draft|active|archived
                $table->unsignedBigInteger('active_version_id')->nullable();
                $table->timestamps();

                $table->index(['profile_id', 'status'], 'tos_strat_profile_status_idx');
                $table->foreign('profile_id')->references('id')->on('portfolio_profiles')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('portfolio_tos_strategy_versions')) {
            Schema::create('portfolio_tos_strategy_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('strategy_id');
                $table->unsignedInteger('version');
                $table->json('config_json');
                $table->string('status', 32)->default('draft'); // draft|active|superseded
                $table->text('change_notes')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();

                $table->unique(['strategy_id', 'version'], 'tos_strat_ver_unique');
                $table->index(['strategy_id', 'status'], 'tos_strat_ver_status_idx');
                $table->foreign('strategy_id')->references('id')->on('portfolio_tos_strategies')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('portfolio_tos_strategies') && Schema::hasTable('portfolio_tos_strategy_versions')) {
            // Soft FK: active_version_id → versions (avoid circular create issues)
        }

        if (Schema::hasTable('portfolio_tos_recommendations')) {
            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'strategy_version_id')) {
                    $table->unsignedBigInteger('strategy_version_id')->nullable()->after('evaluation_result_id');
                    $table->index('strategy_version_id', 'tos_rec_strat_ver_idx');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'strategy_score')) {
                    $table->decimal('strategy_score', 12, 4)->nullable()->after('priority');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_recommendations')) {
            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                if (Schema::hasColumn('portfolio_tos_recommendations', 'strategy_version_id')) {
                    $table->dropIndex('tos_rec_strat_ver_idx');
                    $table->dropColumn('strategy_version_id');
                }
                if (Schema::hasColumn('portfolio_tos_recommendations', 'strategy_score')) {
                    $table->dropColumn('strategy_score');
                }
            });
        }

        Schema::dropIfExists('portfolio_tos_strategy_versions');
        Schema::dropIfExists('portfolio_tos_strategies');
    }
};
