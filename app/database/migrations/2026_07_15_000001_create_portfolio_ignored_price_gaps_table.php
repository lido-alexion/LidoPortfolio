<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_ignored_price_gaps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_id');
            $table->date('gap_from');
            $table->date('gap_to');
            $table->unsignedBigInteger('ignored_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'gap_from', 'gap_to'], 'portfolio_ignored_price_gaps_unique');
            $table->index('stock_id');
            $table->foreign('stock_id')
                ->references('id')
                ->on('portfolio_stocks')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_ignored_price_gaps');
    }
};
