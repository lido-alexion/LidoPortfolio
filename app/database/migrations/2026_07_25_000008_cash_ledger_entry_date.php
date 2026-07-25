<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business date for cash ledger movements (deposit / withdraw / adjust).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_cash_ledger_entries')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_cash_ledger_entries', 'entry_date')) {
            Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
                $table->date('entry_date')->nullable()->after('reason');
                $table->index(['profile_id', 'entry_date'], 'cash_ledger_profile_entry_date_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_cash_ledger_entries')) {
            return;
        }

        if (Schema::hasColumn('portfolio_cash_ledger_entries', 'entry_date')) {
            Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
                $table->dropIndex('cash_ledger_profile_entry_date_idx');
                $table->dropColumn('entry_date');
            });
        }
    }
};
