<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portfolio_cash_ledger_entries', 'entry_classification')) {
            Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
                $table->string('entry_classification', 48)->nullable()->after('entry_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_cash_ledger_entries', 'entry_classification')) {
            Schema::table('portfolio_cash_ledger_entries', fn (Blueprint $table) => $table->dropColumn('entry_classification'));
        }
    }
};
