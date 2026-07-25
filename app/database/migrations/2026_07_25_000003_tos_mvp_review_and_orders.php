<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MVP completion: recommendation user-review history, reference price for outcomes,
 * and order cancel support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
            $table->decimal('reference_price', 18, 4)->nullable()->after('suggested_position_size');
        });

        // Map prior "active" rows to pending_review (user must review before acting).
        if (Schema::hasTable('portfolio_tos_recommendations')) {
            DB::table('portfolio_tos_recommendations')
                ->where('status', 'active')
                ->update(['status' => 'pending_review']);
        }

        Schema::create('portfolio_tos_recommendation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained('portfolio_tos_recommendations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('decision', 32);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recommendation_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('portfolio_tos_orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('executed_at');
            $table->decimal('limit_price', 18, 4)->nullable()->after('quantity');
            $table->text('notes')->nullable()->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_tos_orders', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'limit_price', 'notes']);
        });

        Schema::dropIfExists('portfolio_tos_recommendation_reviews');

        if (Schema::hasTable('portfolio_tos_recommendations')) {
            DB::table('portfolio_tos_recommendations')
                ->where('status', 'pending_review')
                ->update(['status' => 'active']);
        }

        Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
            $table->dropColumn('reference_price');
        });
    }
};
