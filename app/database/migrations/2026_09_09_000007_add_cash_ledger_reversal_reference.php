<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_of_entry_id')->nullable()->after('reason');
            $table->foreign('reversal_of_entry_id', 'cash_ledger_reversal_fk')
                ->references('id')->on('portfolio_cash_ledger_entries')->nullOnDelete();
            $table->unique('reversal_of_entry_id', 'cash_ledger_one_reversal_uk');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_cash_ledger_entries', function (Blueprint $table) {
            $table->dropForeign('cash_ledger_reversal_fk');
            $table->dropUnique('cash_ledger_one_reversal_uk');
            $table->dropColumn('reversal_of_entry_id');
        });
    }
};
