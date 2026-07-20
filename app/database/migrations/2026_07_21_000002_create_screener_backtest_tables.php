<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_screener_backtests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screener_id');
            $table->unsignedBigInteger('profile_id');
            $table->uuid('session_token');
            $table->string('range_key', 8);
            $table->string('status', 16)->default('running');
            $table->date('from_date');
            $table->date('to_date');
            $table->json('stats_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('session_token', 'portfolio_screener_backtests_session_idx');
            $table->index(['screener_id', 'created_at'], 'portfolio_screener_backtests_screener_created_idx');
            $table->foreign('screener_id')->references('id')->on('portfolio_screeners')->cascadeOnDelete();
            $table->foreign('profile_id')->references('id')->on('portfolio_profiles')->cascadeOnDelete();
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_screener_backtest_hits');
        Schema::dropIfExists('portfolio_screener_backtests');
    }
};
