<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill: informational HOLD/WATCH recommendations that were left in pending_review
 * become published (no user approval required).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_recommendations')) {
            return;
        }

        DB::table('portfolio_tos_recommendations')
            ->whereIn('recommendation_type', ['HOLD', 'WATCH', 'hold', 'watch'])
            ->whereIn('status', ['pending_review', 'active'])
            ->update(['status' => 'published']);
    }

    public function down(): void
    {
        // Irreversible data backfill — prior pending_review rows cannot be restored uniquely.
    }
};
