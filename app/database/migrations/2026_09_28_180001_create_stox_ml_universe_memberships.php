<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_ml_universe_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('universe_key', 64);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('sector_snapshot')->nullable();
            $table->string('source', 64);
            $table->string('snapshot_key', 128)->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'universe_key', 'effective_from'], 'stox_ml_membership_period_uq');
            $table->index(['universe_key', 'effective_from', 'effective_to'], 'stox_ml_membership_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_universe_memberships');
    }
};
