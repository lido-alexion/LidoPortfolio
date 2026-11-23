<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_cash_ledger_entries') && ! Schema::hasColumn('portfolio_cash_ledger_entries', 'broker_account_id')) {
            Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
                $table->string('broker_account_id', 64)->nullable()->after('profile_id');
                $table->index(['profile_id', 'broker_account_id', 'entry_date'], 'cash_ledger_broker_date_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_cash_ledger_entries') && Schema::hasColumn('portfolio_cash_ledger_entries', 'broker_account_id')) {
            Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
                $table->dropIndex('cash_ledger_broker_date_idx');
                $table->dropColumn('broker_account_id');
            });
        }
    }
};
