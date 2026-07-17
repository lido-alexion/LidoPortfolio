<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_watchlist_pattern_scans')) {
            return;
        }

        Schema::create('portfolio_watchlist_pattern_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('watchlist_id')->constrained('portfolio_watchlists')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->json('matches');
            $table->date('price_as_of')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->unique(['watchlist_id', 'stock_id'], 'pwps_watchlist_stock_unique');
            $table->index(['watchlist_id', 'expires_at'], 'pwps_watchlist_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_watchlist_pattern_scans');
    }
};
