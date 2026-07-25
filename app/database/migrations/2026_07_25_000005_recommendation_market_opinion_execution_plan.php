<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Position-aware recommendations: widen type, add market_opinion / execution_plan JSON,
 * allocation fields, and migrate legacy BUY/SELL/HOLD/WATCH values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_recommendations')) {
            return;
        }

        Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_tos_recommendations', 'market_opinion')) {
                $table->json('market_opinion')->nullable();
            }
            if (! Schema::hasColumn('portfolio_tos_recommendations', 'execution_plan')) {
                $table->json('execution_plan')->nullable();
            }
            if (! Schema::hasColumn('portfolio_tos_recommendations', 'current_allocation_pct')) {
                $table->decimal('current_allocation_pct', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('portfolio_tos_recommendations', 'target_allocation_pct')) {
                $table->decimal('target_allocation_pct', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('portfolio_tos_recommendations', 'suggested_allocation_pct')) {
                $table->decimal('suggested_allocation_pct', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('portfolio_tos_recommendations', 'reasoning')) {
                $table->text('reasoning')->nullable();
            }
        });

        // Widen type for OPEN_POSITION / INCREASE_POSITION etc. (avoid doctrine/dbal dependency).
        try {
            DB::statement('ALTER TABLE portfolio_tos_recommendations MODIFY recommendation_type VARCHAR(32) NOT NULL');
        } catch (\Throwable) {
            // SQLite / already widened — ignore
        }

        $map = [
            'BUY' => 'OPEN_POSITION',
            'buy' => 'OPEN_POSITION',
            'SELL' => 'EXIT_POSITION',
            'sell' => 'EXIT_POSITION',
            'HOLD' => 'HOLD_POSITION',
            'hold' => 'HOLD_POSITION',
            'WATCH' => 'WATCH',
            'watch' => 'WATCH',
        ];

        foreach ($map as $from => $to) {
            DB::table('portfolio_tos_recommendations')
                ->where('recommendation_type', $from)
                ->update(['recommendation_type' => $to]);
        }

        DB::table('portfolio_tos_recommendations')
            ->whereIn('recommendation_type', ['HOLD_POSITION', 'WATCH', 'HOLD', 'hold', 'watch'])
            ->whereIn('status', ['pending_review', 'active'])
            ->update(['status' => 'published']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_tos_recommendations')) {
            return;
        }

        $map = [
            'OPEN_POSITION' => 'BUY',
            'INCREASE_POSITION' => 'BUY',
            'EXIT_POSITION' => 'SELL',
            'REDUCE_POSITION' => 'SELL',
            'HOLD_POSITION' => 'HOLD',
            'WATCH' => 'WATCH',
        ];

        foreach ($map as $from => $to) {
            DB::table('portfolio_tos_recommendations')
                ->where('recommendation_type', $from)
                ->update(['recommendation_type' => $to]);
        }

        Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_tos_recommendations', 'reasoning')) {
                $table->dropColumn('reasoning');
            }
            if (Schema::hasColumn('portfolio_tos_recommendations', 'suggested_allocation_pct')) {
                $table->dropColumn('suggested_allocation_pct');
            }
            if (Schema::hasColumn('portfolio_tos_recommendations', 'target_allocation_pct')) {
                $table->dropColumn('target_allocation_pct');
            }
            if (Schema::hasColumn('portfolio_tos_recommendations', 'current_allocation_pct')) {
                $table->dropColumn('current_allocation_pct');
            }
            if (Schema::hasColumn('portfolio_tos_recommendations', 'execution_plan')) {
                $table->dropColumn('execution_plan');
            }
            if (Schema::hasColumn('portfolio_tos_recommendations', 'market_opinion')) {
                $table->dropColumn('market_opinion');
            }
        });
    }
};
