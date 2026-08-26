<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 §19: persist NIFTY-comparable benchmark return and success boolean on closed backtest trades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_backtest_trades', function (Blueprint $table) {
            $table->decimal('benchmark_return_pct', 12, 6)->nullable()->after('return_pct');
            $table->boolean('is_success')->nullable()->after('is_open');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_backtest_trades', function (Blueprint $table) {
            $table->dropColumn(['benchmark_return_pct', 'is_success']);
        });
    }
};
