<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_yahoo_fundamental_symbol_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->unsignedInteger('consecutive_no_data')->default(0);
            $table->timestamp('last_no_data_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['skipped_at', 'consecutive_no_data'], 'stox_yahoo_fund_state_skip_idx');
        });

        Schema::create('stox_yahoo_fundamental_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('stox_fundamental_update_runs')->nullOnDelete();
            $table->string('outcome', 24);
            $table->string('cadences', 64)->nullable();
            $table->unsignedInteger('consecutive_count')->default(0);
            $table->timestamp('observed_at');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['stock_id', 'run_id'], 'stox_yahoo_fund_obs_stock_run_uq');
            $table->index(['stock_id', 'observed_at'], 'stox_yahoo_fund_obs_stock_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_yahoo_fundamental_observations');
        Schema::dropIfExists('stox_yahoo_fundamental_symbol_state');
    }
};
