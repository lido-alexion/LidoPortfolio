<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_watchlist_items')) {
            return;
        }

        Schema::create('portfolio_watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'stock_id'], 'pwi_profile_stock_unique');
            $table->index(['profile_id', 'updated_at'], 'pwi_profile_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_watchlist_items');
    }
};
