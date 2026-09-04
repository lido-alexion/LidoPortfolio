<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_internal_execution_transfers', function (Blueprint $table) {
            $table->foreignId('sell_transaction_id')->nullable()->constrained('portfolio_transactions')->nullOnDelete();
            $table->foreignId('buy_transaction_id')->nullable()->constrained('portfolio_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_internal_execution_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sell_transaction_id');
            $table->dropConstrainedForeignId('buy_transaction_id');
        });
    }
};
