<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_ml_universe_snapshot_boundaries', function (Blueprint $table): void {
            $table->id();
            $table->string('universe_key', 64);
            $table->date('effective_from');
            $table->string('source', 64);
            $table->string('snapshot_key', 128);
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
            $table->unique(['universe_key', 'effective_from'], 'stox_ml_universe_snapshot_boundary_uq');
            $table->index(['universe_key', 'effective_from'], 'stox_ml_universe_snapshot_boundary_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_universe_snapshot_boundaries');
    }
};
