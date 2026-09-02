<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_recommendations')) {
            return;
        }

        DB::table('portfolio_tos_recommendations')
            ->whereIn('recommendation_type', ['HOLD', 'HOLD_POSITION'])
            ->whereIn('status', ['accepted', 'pending_execution'])
            ->update([
                'status' => 'expired',
                'expires_at' => DB::raw('COALESCE(expires_at, CURRENT_TIMESTAMP)'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Historical lifecycle states cannot be reconstructed safely.
    }
};
