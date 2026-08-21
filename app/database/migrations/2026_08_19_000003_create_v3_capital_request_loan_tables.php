<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 Workstream 4 Step 1 — capital request / loan / loan-return data foundation.
 *
 * Persistence only. Does not implement lending, recall, cash accounting, or execution.
 * Physical cash tables are not modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_tos_capital_requests')) {
            Schema::create('portfolio_tos_capital_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('borrower_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->foreignId('lender_strategy_id')->nullable()->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->foreignId('recommendation_id')->constrained('portfolio_tos_recommendations')->restrictOnDelete();
                $table->decimal('amount', 18, 4);
                $table->string('status', 32);
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['profile_id', 'status'], 'tos_creq_profile_status_idx');
                $table->index('borrower_strategy_id', 'tos_creq_borrower_idx');
                $table->index('lender_strategy_id', 'tos_creq_lender_idx');
                $table->index('recommendation_id', 'tos_creq_rec_idx');
            });
        }

        if (! Schema::hasTable('portfolio_tos_loans')) {
            Schema::create('portfolio_tos_loans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('capital_request_id')->unique()->constrained('portfolio_tos_capital_requests')->restrictOnDelete();
                $table->foreignId('borrower_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->foreignId('lender_strategy_id')->constrained('portfolio_tos_strategies')->restrictOnDelete();
                $table->decimal('principal', 18, 4);
                $table->decimal('outstanding', 18, 4);
                $table->timestamp('committed_at');
                $table->timestamp('min_recall_at')->nullable();
                $table->string('status', 32);
                $table->timestamps();

                $table->index(['profile_id', 'status'], 'tos_loans_profile_status_idx');
                $table->index(['lender_strategy_id', 'outstanding'], 'tos_loans_lender_outstanding_idx');
                $table->index('borrower_strategy_id', 'tos_loans_borrower_idx');
                $table->index('committed_at', 'tos_loans_committed_at_idx');
            });
        }

        if (! Schema::hasTable('portfolio_tos_loan_returns')) {
            Schema::create('portfolio_tos_loan_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->constrained('portfolio_tos_loans')->restrictOnDelete();
                $table->foreignId('capital_request_id')->nullable()->constrained('portfolio_tos_capital_requests')->restrictOnDelete();
                $table->decimal('amount', 18, 4);
                $table->timestamp('returned_at');
                $table->timestamp('created_at')->useCurrent();

                $table->index('loan_id', 'tos_lret_loan_idx');
                $table->index('capital_request_id', 'tos_lret_request_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_loan_returns');
        Schema::dropIfExists('portfolio_tos_loans');
        Schema::dropIfExists('portfolio_tos_capital_requests');
    }
};
