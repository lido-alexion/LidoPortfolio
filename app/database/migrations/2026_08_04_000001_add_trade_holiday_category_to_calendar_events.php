<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_calendar_events')) {
            return;
        }

        Schema::table('portfolio_calendar_events', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_calendar_events', 'category')) {
                $table->string('category', 32)->nullable()->after('profile_id');
                $table->index(['category', 'is_active'], 'pce_category_active_idx');
            }
        });

        // Allow global (profile_id null) trade-holiday events.
        try {
            Schema::table('portfolio_calendar_events', function (Blueprint $table) {
                $table->dropForeign(['profile_id']);
            });
        } catch (\Throwable) {
            // SQLite / already dropped.
        }

        Schema::table('portfolio_calendar_events', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id')->nullable()->change();
        });

        try {
            Schema::table('portfolio_calendar_events', function (Blueprint $table) {
                $table->foreign('profile_id')
                    ->references('id')
                    ->on('portfolio_profiles')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // SQLite may not support re-adding the FK the same way.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_calendar_events')) {
            return;
        }

        // Cannot safely restore NOT NULL while global rows exist — drop category only.
        Schema::table('portfolio_calendar_events', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_calendar_events', 'category')) {
                $table->dropIndex('pce_category_active_idx');
                $table->dropColumn('category');
            }
        });
    }
};
