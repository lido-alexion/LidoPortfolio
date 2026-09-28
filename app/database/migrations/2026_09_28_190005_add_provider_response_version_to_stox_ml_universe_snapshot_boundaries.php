<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_universe_snapshot_boundaries', function (Blueprint $table): void {
            $table->string('provider_response_version', 128)->nullable()->after('snapshot_key');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_universe_snapshot_boundaries', function (Blueprint $table): void {
            $table->dropColumn('provider_response_version');
        });
    }
};
