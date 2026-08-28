<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V4-SPEC-005 — persist explicit SELL owner attribution on the ledger.
 * Idempotent: safe to re-run after partial failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_transactions')) {
            return;
        }

        if (Schema::hasColumn('portfolio_transactions', 'owner_key')) {
            return;
        }

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->string('owner_key', 64)->nullable()->after('recommendation_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_transactions')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_transactions', 'owner_key')) {
            return;
        }

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropColumn('owner_key');
        });
    }
};
