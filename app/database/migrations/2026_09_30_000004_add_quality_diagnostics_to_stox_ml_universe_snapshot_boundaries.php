<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_universe_snapshot_boundaries', function (Blueprint $table): void {
            $table->json('quality_diagnostics')->nullable()->after('member_count');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_universe_snapshot_boundaries', function (Blueprint $table): void {
            $table->dropColumn('quality_diagnostics');
        });
    }
};
