<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_universe_price_session_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->date('session_date');
            $table->string('exchange', 8);
            $table->string('outcome', 24);
            $table->boolean('price_present')->default(false);
            $table->string('reason', 64)->nullable();
            $table->string('failure_stage', 32)->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('retry_after')->nullable();
            $table->string('source_revision_sha256', 64)->nullable();
            $table->string('resolved_by_source', 64)->nullable();
            $table->string('run_id', 64)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['stock_id', 'session_date'], 'universe_price_session_stock_date_uq');
            $table->index(['session_date', 'outcome'], 'universe_price_session_date_outcome_idx');
            $table->index(['exchange', 'session_date'], 'universe_price_session_exchange_date_idx');
        });

        Schema::create('portfolio_universe_price_symbol_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->unsignedInteger('consecutive_no_data_sessions')->default(0);
            $table->date('last_session_date')->nullable();
            $table->string('last_outcome', 24)->nullable();
            $table->date('skipped_until_session')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['skipped_until_session', 'consecutive_no_data_sessions'], 'universe_price_state_skip_idx');
        });

        Schema::create('portfolio_universe_price_admin_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->string('action', 32);
            $table->date('session_date')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['stock_id', 'created_at'], 'universe_price_admin_stock_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_universe_price_admin_events');
        Schema::dropIfExists('portfolio_universe_price_symbol_states');
        Schema::dropIfExists('portfolio_universe_price_session_outcomes');
    }
};
