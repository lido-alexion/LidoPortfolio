<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V4-FEAT-002 — Strategy-position GTT Target / Stop-Loss protection state.
 * Idempotent: safe to re-run after partial failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_position_protections')) {
            return;
        }

        Schema::create('portfolio_tos_position_protections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('holding_id')->nullable();
            $table->unsignedBigInteger('stock_id');
            $table->unsignedBigInteger('strategy_id')->nullable();
            $table->string('owner_key', 64);
            $table->string('protection_type', 16);
            $table->string('state', 32);
            $table->decimal('trigger_price', 18, 4)->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('broker_gtt_id', 64)->nullable();
            $table->string('broker_status', 32)->nullable();
            $table->string('submission_key', 80)->nullable();
            $table->unsignedBigInteger('trading_order_id')->nullable();
            $table->decimal('last_applied_fill_qty', 18, 4)->default(0);
            $table->boolean('sync_deferred')->default(false);
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('last_error', 191)->nullable();
            $table->string('last_sync_reason', 64)->nullable();
            $table->timestamp('last_broker_sync_at')->nullable();
            $table->timestamp('needs_attention_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'holding_id'], 'tos_prot_profile_holding_idx');
            $table->index(['profile_id', 'stock_id', 'owner_key'], 'tos_prot_profile_stock_owner_idx');
            $table->index(['profile_id', 'state'], 'tos_prot_profile_state_idx');
            $table->unique('submission_key', 'tos_prot_submission_key_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_position_protections');
    }
};
