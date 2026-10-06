<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_settings', function (Blueprint $table): void {
            $table->text('nse_official_feed_url')->nullable()->after('bse_official_fallback_enabled');
            $table->text('bse_official_feed_url')->nullable()->after('nse_official_feed_url');
        });
    }

    public function down(): void
    {
        Schema::table('stox_fundamental_settings', function (Blueprint $table): void {
            $table->dropColumn(['nse_official_feed_url', 'bse_official_feed_url']);
        });
    }
};
