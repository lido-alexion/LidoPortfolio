<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
            $table->decimal('target_amount', 18, 4)->nullable();
            $table->decimal('capital_resolved_amount', 18, 4)->nullable();
            $table->decimal('internal_executed_amount', 18, 4)->default(0);
            $table->decimal('external_executed_amount', 18, 4)->default(0);
            $table->decimal('remaining_target_amount', 18, 4)->nullable();
            $table->decimal('original_display_quantity', 18, 4)->nullable();
            $table->date('execution_anchor_date')->nullable();
            $table->string('execution_anchor_class', 16)->nullable();
            $table->date('first_eligible_execution_date')->nullable();
            $table->date('second_eligible_execution_date')->nullable();
            $table->timestamp('execution_expires_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('portfolio_tos_recommendations')->nullOnDelete();
            $table->index(['status', 'first_eligible_execution_date', 'execution_expires_at'], 'tos_rec_exec_lifetime_idx');
        });

        Schema::create('portfolio_execution_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('provider', 32)->default('kite');
            $table->string('cycle_key', 80)->unique();
            $table->string('status', 32)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('portfolio_internal_execution_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_batch_id')->constrained('portfolio_execution_batches')->cascadeOnDelete();
            $table->foreignId('security_id')->constrained('portfolio_stocks')->restrictOnDelete();
            $table->foreignId('sell_recommendation_id')->constrained('portfolio_tos_recommendations')->restrictOnDelete();
            $table->foreignId('buy_recommendation_id')->constrained('portfolio_tos_recommendations')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('provisional_unit_price', 18, 4);
            $table->decimal('final_unit_price', 18, 4)->nullable();
            $table->string('valuation_status', 24)->default('provisional');
            $table->string('valuation_source', 32)->default('previous_close');
            $table->timestamp('finalized_at')->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->json('audit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_internal_execution_transfers');
        Schema::dropIfExists('portfolio_execution_batches');
        Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
            $table->dropIndex('tos_rec_exec_lifetime_idx');
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropColumn([
                'target_amount', 'capital_resolved_amount', 'internal_executed_amount',
                'external_executed_amount', 'remaining_target_amount', 'original_display_quantity',
                'execution_anchor_date', 'execution_anchor_class', 'first_eligible_execution_date',
                'second_eligible_execution_date', 'execution_expires_at', 'superseded_at',
            ]);
        });
    }
};
