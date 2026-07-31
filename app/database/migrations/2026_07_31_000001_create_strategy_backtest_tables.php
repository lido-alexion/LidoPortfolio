<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strategy Backtesting & Simulation — immutable completed runs + transient resume state.
 * Transient eligibility hits and context_json are cleared when a run completes or is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('strategy_id')->nullable();
            $table->unsignedBigInteger('strategy_version_id')->nullable();
            $table->string('strategy_name', 191)->nullable();
            $table->unsignedInteger('strategy_version_number')->nullable();
            $table->json('entry_screener_versions_json')->nullable();
            $table->json('exit_screener_versions_json')->nullable();
            $table->string('name', 191);
            $table->text('notes')->nullable();
            $table->json('tags_json')->nullable();
            $table->string('range_key', 16)->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('initial_capital', 18, 4);
            $table->string('status', 32)->default('preparing');
            $table->string('stage', 48)->default('PREPARING');
            $table->unsignedInteger('processed_days')->default(0);
            $table->unsignedInteger('total_days')->default(0);
            $table->decimal('progress_pct', 8, 4)->default(0);
            $table->date('current_date')->nullable();
            $table->uuid('session_token')->nullable();
            $table->json('context_json')->nullable();
            $table->json('statistics_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('execution_seconds')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status'], 'portfolio_backtest_runs_profile_status_idx');
            $table->index(['profile_id', 'created_at'], 'portfolio_backtest_runs_profile_created_idx');
            $table->index('session_token', 'portfolio_backtest_runs_session_idx');
            $table->foreign('profile_id')->references('id')->on('portfolio_profiles')->cascadeOnDelete();
        });

        Schema::create('portfolio_backtest_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_run_id');
            $table->date('trade_date');
            $table->unsignedBigInteger('stock_id');
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->decimal('quantity', 18, 4);
            $table->decimal('price', 18, 6);
            $table->decimal('value', 18, 4);
            $table->string('reason', 64)->nullable();
            $table->string('recommendation', 48)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['backtest_run_id', 'trade_date'], 'portfolio_backtest_tx_run_date_idx');
            $table->foreign('backtest_run_id')->references('id')->on('portfolio_backtest_runs')->cascadeOnDelete();
            $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
        });

        Schema::create('portfolio_backtest_trades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_run_id');
            $table->unsignedBigInteger('stock_id');
            $table->string('symbol', 32);
            $table->date('buy_date');
            $table->date('sell_date')->nullable();
            $table->unsignedInteger('holding_days')->nullable();
            $table->decimal('buy_price', 18, 6);
            $table->decimal('sell_price', 18, 6)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('profit_loss', 18, 4)->nullable();
            $table->decimal('return_pct', 12, 6)->nullable();
            $table->decimal('cagr', 12, 6)->nullable();
            $table->string('exit_reason', 64)->nullable();
            $table->boolean('is_open')->default(false);
            $table->timestamps();

            $table->index(['backtest_run_id', 'buy_date'], 'portfolio_backtest_trades_run_buy_idx');
            $table->foreign('backtest_run_id')->references('id')->on('portfolio_backtest_runs')->cascadeOnDelete();
            $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
        });

        Schema::create('portfolio_backtest_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_run_id');
            $table->date('snapshot_date');
            $table->decimal('cash', 18, 4);
            $table->decimal('invested_value', 18, 4);
            $table->decimal('portfolio_value', 18, 4);
            $table->decimal('realized_profit', 18, 4)->default(0);
            $table->decimal('unrealized_profit', 18, 4)->default(0);
            $table->decimal('drawdown_pct', 12, 6)->default(0);
            $table->unsignedInteger('holdings_count')->default(0);
            $table->timestamps();

            $table->unique(['backtest_run_id', 'snapshot_date'], 'portfolio_backtest_snapshots_run_date_uq');
            $table->foreign('backtest_run_id')->references('id')->on('portfolio_backtest_runs')->cascadeOnDelete();
        });

        // Transient eligibility hits for the in-progress run only (deleted on complete/fail/delete).
        Schema::create('portfolio_backtest_run_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_run_id');
            $table->unsignedBigInteger('screener_id');
            $table->string('role', 16);
            $table->date('as_of_date');
            $table->unsignedBigInteger('stock_id');
            $table->timestamps();

            $table->index(['backtest_run_id', 'role', 'as_of_date'], 'portfolio_backtest_run_hits_day_idx');
            $table->index(['backtest_run_id', 'screener_id', 'stock_id'], 'portfolio_backtest_run_hits_stock_idx');
            $table->foreign('backtest_run_id')->references('id')->on('portfolio_backtest_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_backtest_run_hits');
        Schema::dropIfExists('portfolio_backtest_snapshots');
        Schema::dropIfExists('portfolio_backtest_trades');
        Schema::dropIfExists('portfolio_backtest_transactions');
        Schema::dropIfExists('portfolio_backtest_runs');
    }
};
