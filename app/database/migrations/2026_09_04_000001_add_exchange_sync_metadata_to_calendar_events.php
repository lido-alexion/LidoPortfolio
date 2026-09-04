<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('portfolio_calendar_events', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('category');
            $table->string('external_key', 96)->nullable()->after('source');
            $table->boolean('sync_override')->default(false)->after('external_key');
            $table->timestamp('last_synced_at')->nullable()->after('sync_override');
            $table->unique(['source', 'external_key'], 'calendar_source_external_uq');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_calendar_events', function (Blueprint $table) {
            $table->dropUnique('calendar_source_external_uq');
            $table->dropColumn(['source', 'external_key', 'sync_override', 'last_synced_at']);
        });
    }
};
