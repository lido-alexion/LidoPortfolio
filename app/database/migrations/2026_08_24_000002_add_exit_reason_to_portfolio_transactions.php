<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 Phase 2 — §13.5 / §28.6: persist primary exit attribution on sell transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_transactions')) {
            return;
        }
        if (Schema::hasColumn('portfolio_transactions', 'exit_reason')) {
            return;
        }

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->string('exit_reason', 64)->nullable()->after('recommendation_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_transactions')) {
            return;
        }
        if (! Schema::hasColumn('portfolio_transactions', 'exit_reason')) {
            return;
        }

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropColumn('exit_reason');
        });
    }
};
