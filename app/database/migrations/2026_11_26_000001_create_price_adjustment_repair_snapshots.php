<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_price_adjustment_repair_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factor_id')->constrained('portfolio_price_adjustment_factors')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_price_id');
            $table->json('before_row');
            $table->json('after_row')->nullable();
            $table->char('after_sha256', 64)->nullable();
            $table->timestamps();
            $table->unique(['factor_id', 'stock_price_id'], 'ppars_factor_price_uq');
            $table->index('stock_price_id', 'ppars_price_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_price_adjustment_repair_snapshots');
    }
};
