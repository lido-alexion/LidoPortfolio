<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_settings', function (Blueprint $table): void {
            $table->string('ai_insights_primary_provider', 16)->nullable()->after('paused');
        });
    }

    public function down(): void
    {
        Schema::table('stox_fundamental_settings', function (Blueprint $table): void {
            $table->dropColumn('ai_insights_primary_provider');
        });
    }
};
