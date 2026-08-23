<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 WS4 Phase 1 — Recall lifecycle, Recall Bridge Loans, pending sale proceeds.
 *
 * Bridge loans are a separate table (not investment CapitalLoan rows) so normal-loan
 * uniqueness on capital_request_id is preserved. Eligibility remains dynamic —
 * min_recall_at on portfolio_tos_loans is not authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_capital_recalls')) {
            Schema::create('portfolio_tos_capital_recalls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('loan_id')->constrained('portfolio_tos_loans')->restrictOnDelete();
                $table->foreignId('lender_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->foreignId('borrower_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->string('kind', 16);
                $table->decimal('recall_amount', 18, 4);
                $table->decimal('outstanding_recall_amount', 18, 4);
                $table->decimal('settled_amount', 18, 4)->default(0);
                $table->string('state', 32);
                $table->timestamp('requested_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('pending_held_at')->nullable();
                $table->timestamps();

                $table->index(['profile_id', 'state'], 'tos_recall_profile_state_idx');
                $table->index(['loan_id', 'state'], 'tos_recall_loan_state_idx');
                $table->index('lender_strategy_id', 'tos_recall_lender_idx');
                $table->index('borrower_strategy_id', 'tos_recall_borrower_idx');
                $table->index('completed_at', 'tos_recall_completed_at_idx');
            });
        }

        if (! Schema::hasTable('portfolio_tos_recall_bridge_loans')) {
            Schema::create('portfolio_tos_recall_bridge_loans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('capital_recall_id')->constrained('portfolio_tos_capital_recalls')->restrictOnDelete();
                $table->foreignId('borrower_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->foreignId('lender_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->decimal('principal', 18, 4);
                $table->decimal('outstanding', 18, 4);
                $table->timestamp('committed_at');
                $table->string('status', 32);
                $table->timestamps();

                $table->index(['profile_id', 'status'], 'tos_bridge_profile_status_idx');
                $table->index('capital_recall_id', 'tos_bridge_recall_idx');
                $table->index(['lender_strategy_id', 'outstanding'], 'tos_bridge_lender_out_idx');
                $table->index('borrower_strategy_id', 'tos_bridge_borrower_idx');
            });
        }

        if (! Schema::hasTable('portfolio_tos_pending_sale_proceeds')) {
            Schema::create('portfolio_tos_pending_sale_proceeds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->foreignId('capital_recall_id')->nullable()->constrained('portfolio_tos_capital_recalls')->nullOnDelete();
                $table->decimal('amount', 18, 4);
                $table->timestamp('sold_at');
                $table->timestamp('available_at');
                $table->string('status', 32);
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['profile_id', 'status'], 'tos_psp_profile_status_idx');
                $table->index(['strategy_id', 'status'], 'tos_psp_strategy_status_idx');
                $table->index('available_at', 'tos_psp_available_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_pending_sale_proceeds');
        Schema::dropIfExists('portfolio_tos_recall_bridge_loans');
        Schema::dropIfExists('portfolio_tos_capital_recalls');
    }
};
