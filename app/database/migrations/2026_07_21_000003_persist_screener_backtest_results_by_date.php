<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-(screener, date) persistent backtest result cache. Time is irrelevant:
        // one row per screener per as-of date; re-running a backtest reuses these rows.
        Schema::create('portfolio_screener_backtest_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screener_id');
            $table->date('as_of_date');
            $table->unsignedInteger('scanned')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('skipped_insufficient_data')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->timestamps();

            $table->unique(['screener_id', 'as_of_date'], 'portfolio_screener_backtest_days_unique');
            $table->foreign('screener_id')->references('id')->on('portfolio_screeners')->cascadeOnDelete();
        });

        // Hits move from per-backtest-session to per-(screener, date). Old rows were
        // session-scoped and disposable, so drop and recreate with the new key.
        Schema::dropIfExists('portfolio_screener_backtest_hits');
        Schema::create('portfolio_screener_backtest_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screener_id');
            $table->date('as_of_date');
            $table->unsignedBigInteger('stock_id');
            $table->string('symbol', 32);
            $table->string('exchange', 16)->nullable();
            $table->string('name', 255)->nullable();
            $table->timestamps();

            $table->index(['screener_id', 'as_of_date'], 'portfolio_screener_backtest_hits_day_idx');
            $table->index(['screener_id', 'symbol'], 'portfolio_screener_backtest_hits_symbol_idx');
            $table->foreign('screener_id')->references('id')->on('portfolio_screeners')->cascadeOnDelete();
            $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_screener_backtest_hits');
        Schema::create('portfolio_screener_backtest_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_id');
            $table->date('as_of_date');
            $table->unsignedBigInteger('stock_id');
            $table->string('symbol', 32);
            $table->string('exchange', 16)->nullable();
            $table->string('name', 255)->nullable();
            $table->timestamps();

            $table->index(['backtest_id', 'as_of_date'], 'portfolio_screener_backtest_hits_day_idx');
            $table->index(['backtest_id', 'symbol'], 'portfolio_screener_backtest_hits_symbol_idx');
            $table->foreign('backtest_id')->references('id')->on('portfolio_screener_backtests')->cascadeOnDelete();
            $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
        });
        Schema::dropIfExists('portfolio_screener_backtest_days');
    }
};
