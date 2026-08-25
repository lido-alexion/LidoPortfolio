<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_holding_adoptions')) {
            return;
        }

        Schema::create('portfolio_holding_adoptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('holding_id')->nullable();
            $table->unsignedBigInteger('stock_id');
            $table->string('from_owner_key', 64);
            $table->unsignedBigInteger('to_strategy_id');
            $table->string('to_owner_key', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('attribution_recommendation_id')->nullable();
            $table->decimal('target_amount', 18, 4)->nullable();
            $table->boolean('idempotent')->default(false);
            $table->json('evidence_json')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'stock_id'], 'pha_profile_stock_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_holding_adoptions');
    }
};
