<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_backtest_trades', function (Blueprint $table) {
            $table->decimal('entry_score', 8, 4)->nullable()->after('exit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_backtest_trades', function (Blueprint $table) {
            $table->dropColumn('entry_score');
        });
    }
};
