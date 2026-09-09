<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_internal_execution_transfers', function (Blueprint $table) {
            $table->foreignId('sell_transaction_id')->nullable()
                ->constrained('portfolio_transactions', indexName: 'internal_exec_transfer_sell_tx_fk')
                ->nullOnDelete();
            $table->foreignId('buy_transaction_id')->nullable()
                ->constrained('portfolio_transactions', indexName: 'internal_exec_transfer_buy_tx_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_internal_execution_transfers', function (Blueprint $table) {
            $table->dropForeign('internal_exec_transfer_sell_tx_fk');
            $table->dropForeign('internal_exec_transfer_buy_tx_fk');
            $table->dropColumn(['sell_transaction_id', 'buy_transaction_id']);
        });
    }
};
