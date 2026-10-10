<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_screener_run_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->string('symbol', 32);
            $table->string('exchange', 16)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('outcome', 24);
            $table->string('reason', 64)->nullable();
            $table->json('metrics_json')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'outcome', 'symbol'], 'screener_run_diag_run_outcome_symbol_idx');
            $table->foreign('run_id')->references('id')->on('portfolio_screener_runs')->cascadeOnDelete();
            $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_screener_run_diagnostics');
    }
};
