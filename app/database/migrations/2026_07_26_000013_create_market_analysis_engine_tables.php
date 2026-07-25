<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SD-032: Market Analysis Engine — persisted market analytics snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_market_analytics')) {
            Schema::create('portfolio_tos_market_analytics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('benchmark_stock_id');
                $table->date('as_of_date');
                $table->string('market_phase', 32);
                $table->decimal('sentiment_score', 8, 4);
                $table->string('sentiment_label', 32)->nullable();
                $table->json('payload_json');
                $table->json('explainability_json')->nullable();
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();

                $table->unique(['benchmark_stock_id', 'as_of_date'], 'tos_mkt_bench_date_uq');
                $table->index(['as_of_date'], 'tos_mkt_asof_idx');
                $table->foreign('benchmark_stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_market_analytics');
    }
};
