<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SD-031: Cached analytics snapshots (portfolio / market) for reuse across pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_analytics_snapshots')) {
            Schema::create('portfolio_analytics_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('profile_id');
                $table->string('category', 32); // portfolio|market
                $table->string('cache_key', 64)->default('default');
                $table->json('payload_json');
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();

                $table->unique(['profile_id', 'category', 'cache_key'], 'pa_snap_profile_cat_key_uq');
                $table->foreign('profile_id')->references('id')->on('portfolio_profiles')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('portfolio_stock_analytics_cache')) {
            Schema::create('portfolio_stock_analytics_cache', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('stock_id');
                $table->json('payload_json');
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();

                $table->unique(['stock_id'], 'psa_stock_uq');
                $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_stock_analytics_cache');
        Schema::dropIfExists('portfolio_analytics_snapshots');
    }
};
