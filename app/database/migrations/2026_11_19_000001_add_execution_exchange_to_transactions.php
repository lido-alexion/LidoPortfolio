<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->string('execution_exchange', 3)->default('NSE')->after('stock_id');
            $table->index(['profile_id', 'transaction_date', 'execution_exchange'], 'pt_exec_exchange_date_idx');
        });
        // The column default initializes legacy rows without touching fees or cash history.
    }

    public function down(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropIndex('pt_exec_exchange_date_idx');
            $table->dropColumn('execution_exchange');
        });
    }
};
