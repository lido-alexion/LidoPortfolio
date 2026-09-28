<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_universe_snapshot_backfill_runs', function (Blueprint $table): void {
            $table->json('retry_counts')->nullable()->after('failed_dates');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_universe_snapshot_backfill_runs', function (Blueprint $table): void {
            $table->dropColumn('retry_counts');
        });
    }
};
