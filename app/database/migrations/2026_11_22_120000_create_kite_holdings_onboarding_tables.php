<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kite_onboarding_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->json('kite_snapshot');
            $table->timestamp('snapshot_at');
            $table->timestamps();
            $table->unique('profile_id', 'kite_onboard_profile_uq');
        });
        Schema::create('kite_onboarding_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('kite_onboarding_drafts')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('exchange', 8)->nullable();
            $table->decimal('kite_quantity', 18, 4);
            $table->decimal('kite_average_price', 18, 4)->nullable();
            $table->json('entries');
            $table->string('status', 20)->default('needs_review');
            $table->json('audit')->nullable();
            $table->timestamps();
            $table->unique(['draft_id', 'stock_id'], 'kite_onboard_stock_uq');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('kite_onboarding_stocks');
        Schema::dropIfExists('kite_onboarding_drafts');
    }
};
