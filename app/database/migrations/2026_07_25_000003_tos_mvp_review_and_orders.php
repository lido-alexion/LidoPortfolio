<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MVP completion: recommendation user-review history, reference price for outcomes,
 * and order cancel support.
 * Idempotent: safe to re-run after partial failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_recommendations')
            && ! Schema::hasColumn('portfolio_tos_recommendations', 'reference_price')) {
            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                $table->decimal('reference_price', 18, 4)->nullable()->after('suggested_position_size');
            });
        }

        if (Schema::hasTable('portfolio_tos_recommendations')) {
            DB::table('portfolio_tos_recommendations')
                ->where('status', 'active')
                ->update(['status' => 'pending_review']);
        }

        if (! Schema::hasTable('portfolio_tos_recommendation_reviews')) {
            Schema::create('portfolio_tos_recommendation_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recommendation_id')->constrained('portfolio_tos_recommendations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
                $table->string('decision', 32);
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();

                // Short names: auto-generated names exceed MySQL's 64-char limit.
                $table->index(['recommendation_id', 'created_at'], 'tos_rec_rev_rec_created_idx');
                $table->index(['user_id', 'created_at'], 'tos_rec_rev_user_created_idx');
            });
        }

        if (Schema::hasTable('portfolio_tos_orders') && ! Schema::hasColumn('portfolio_tos_orders', 'cancelled_at')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable()->after('executed_at');
            });
        }
        if (Schema::hasTable('portfolio_tos_orders') && ! Schema::hasColumn('portfolio_tos_orders', 'limit_price')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                $table->decimal('limit_price', 18, 4)->nullable()->after('quantity');
            });
        }
        if (Schema::hasTable('portfolio_tos_orders') && ! Schema::hasColumn('portfolio_tos_orders', 'notes')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('order_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_orders')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    ['cancelled_at', 'limit_price', 'notes'],
                    fn (string $c) => Schema::hasColumn('portfolio_tos_orders', $c),
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        Schema::dropIfExists('portfolio_tos_recommendation_reviews');

        if (Schema::hasTable('portfolio_tos_recommendations')) {
            DB::table('portfolio_tos_recommendations')
                ->where('status', 'pending_review')
                ->update(['status' => 'active']);

            if (Schema::hasColumn('portfolio_tos_recommendations', 'reference_price')) {
                Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                    $table->dropColumn('reference_price');
                });
            }
        }
    }
};
