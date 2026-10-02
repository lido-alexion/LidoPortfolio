<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_universe_snapshot_backfill_runs', function (Blueprint $table): void {
            $table->json('source_diagnostics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_universe_snapshot_backfill_runs', function (Blueprint $table): void {
            $table->dropColumn('source_diagnostics');
        });
    }
};
