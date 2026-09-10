<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_backtest_runs', function (Blueprint $table) {
            $table->json('execution_assumptions_json')->nullable()->after('initial_capital');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_backtest_runs', fn (Blueprint $table) => $table->dropColumn('execution_assumptions_json'));
    }
};
