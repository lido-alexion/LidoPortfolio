<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 WS4 Phase 2 — pending sale proceeds obligation linkage + expected vs actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_pending_sale_proceeds')) {
            return;
        }

        Schema::table('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'expected_amount')) {
                $table->decimal('expected_amount', 18, 4)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'obligation_type')) {
                $table->string('obligation_type', 16)->nullable()->after('capital_recall_id');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'recall_bridge_loan_id')) {
                $table->unsignedBigInteger('recall_bridge_loan_id')->nullable()->after('obligation_type');
                $table->index('recall_bridge_loan_id', 'tos_psp_bridge_idx');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'transaction_id')) {
                $table->unsignedBigInteger('transaction_id')->nullable()->after('recall_bridge_loan_id');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'cash_released_at')) {
                $table->timestamp('cash_released_at')->nullable()->after('applied_at');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'required_settlement_amount')) {
                $table->decimal('required_settlement_amount', 18, 4)->nullable()->after('expected_amount');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'target_liquidation_value')) {
                $table->decimal('target_liquidation_value', 18, 4)->nullable()->after('required_settlement_amount');
            }
            if (! Schema::hasColumn('portfolio_tos_pending_sale_proceeds', 'sale_buffer_amount')) {
                $table->decimal('sale_buffer_amount', 18, 4)->nullable()->after('target_liquidation_value');
            }
        });

        if (Schema::hasTable('portfolio_tos_recall_bridge_loans')) {
            try {
                Schema::table('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
                    $table->foreign('recall_bridge_loan_id', 'tos_psp_bridge_fk')
                        ->references('id')
                        ->on('portfolio_tos_recall_bridge_loans')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }

        if (Schema::hasTable('portfolio_transactions')) {
            try {
                Schema::table('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
                    $table->foreign('transaction_id', 'tos_psp_tx_fk')
                        ->references('id')
                        ->on('portfolio_transactions')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_tos_pending_sale_proceeds')) {
            return;
        }

        try {
            Schema::table('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
                $table->dropForeign('tos_psp_bridge_fk');
            });
        } catch (\Throwable) {
        }
        try {
            Schema::table('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
                $table->dropForeign('tos_psp_tx_fk');
            });
        } catch (\Throwable) {
        }

        Schema::table('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
            foreach ([
                'expected_amount',
                'obligation_type',
                'recall_bridge_loan_id',
                'transaction_id',
                'cash_released_at',
                'required_settlement_amount',
                'target_liquidation_value',
                'sale_buffer_amount',
            ] as $col) {
                if (Schema::hasColumn('portfolio_tos_pending_sale_proceeds', $col)) {
                    if ($col === 'recall_bridge_loan_id') {
                        try {
                            $table->dropIndex('tos_psp_bridge_idx');
                        } catch (\Throwable) {
                        }
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
