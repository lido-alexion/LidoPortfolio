<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_backtest_runs', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
        Schema::create('portfolio_backtest_run_tombstones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_run_id')->unique();
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->unsignedBigInteger('strategy_id')->nullable();
            $table->string('final_status', 32);
            $table->timestamp('run_created_at');
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamp('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_backtest_run_tombstones');
        Schema::table('portfolio_backtest_runs', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
    }
};
