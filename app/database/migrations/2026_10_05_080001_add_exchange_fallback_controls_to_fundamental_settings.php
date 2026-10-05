<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_settings', function (Blueprint $table): void {
            $table->boolean('nse_official_fallback_enabled')->default(false)->after('paused');
            $table->boolean('bse_official_fallback_enabled')->default(false)->after('nse_official_fallback_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('stox_fundamental_settings', function (Blueprint $table): void {
            $table->dropColumn(['nse_official_fallback_enabled', 'bse_official_fallback_enabled']);
        });
    }
};
