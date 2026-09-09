<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_benchmarks', function (Blueprint $table) {
            $table->id();
            $table->string('stable_key', 64)->unique();
            $table->string('name');
            $table->string('symbol', 64);
            $table->string('return_type', 16)->default('total_return');
            $table->string('currency', 3)->default('INR');
            $table->string('provider', 64);
            $table->text('provenance')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_default'], 'benchmark_active_default_idx');
        });

        Schema::create('portfolio_analysis_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('primary_benchmark_id')->nullable()->constrained('portfolio_benchmarks')->nullOnDelete();
            $table->json('comparison_benchmark_ids')->nullable();
            $table->boolean('include_in_account_performance')->default(true);
            $table->boolean('include_in_account_tax')->default(true);
            $table->decimal('risk_free_rate', 12, 8)->nullable();
            $table->unsignedSmallInteger('annualization_days')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'profile_id'], 'analysis_preferences_scope_uq');
        });

        Schema::create('portfolio_tax_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 32)->unique();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('rules');
            $table->string('source_reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['effective_from', 'effective_to'], 'tax_rule_effective_idx');
        });

        Schema::create('portfolio_opening_tax_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->restrictOnDelete();
            $table->date('acquired_on');
            $table->decimal('quantity', 18, 4);
            $table->decimal('cost_basis', 18, 4);
            $table->string('source', 64)->default('manual');
            $table->string('external_reference')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('portfolio_users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'stock_id', 'acquired_on'], 'opening_tax_lot_fifo_idx');
        });

        Schema::create('portfolio_tax_losses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('financial_year', 9);
            $table->string('loss_type', 16);
            $table->decimal('amount', 18, 4);
            $table->string('status', 16)->default('calculated');
            $table->string('external_reference')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'financial_year', 'loss_type'], 'tax_loss_account_fy_idx');
        });

        Schema::create('portfolio_dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('portfolio_stocks')->nullOnDelete();
            $table->date('received_on');
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('INR');
            $table->string('source', 32)->default('manual');
            $table->string('source_reference')->nullable();
            $table->string('deduplication_key', 191);
            $table->json('source_evidence')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'deduplication_key'], 'dividend_account_dedupe_uq');
            $table->index(['user_id', 'received_on'], 'dividend_account_date_idx');
        });

        Schema::create('portfolio_analysis_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('portfolio_profiles')->nullOnDelete();
            $table->string('calculation_type', 32);
            $table->string('calculation_mode', 16)->default('configured');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('request_cutoff_at');
            $table->string('completeness', 32);
            $table->json('assumptions');
            $table->json('inputs_digest');
            $table->json('result');
            $table->timestamps();

            $table->index(['user_id', 'calculation_type', 'period_end'], 'analysis_evidence_account_idx');
            $table->index(['profile_id', 'calculation_type', 'period_end'], 'analysis_evidence_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_analysis_evidence');
        Schema::dropIfExists('portfolio_dividends');
        Schema::dropIfExists('portfolio_tax_losses');
        Schema::dropIfExists('portfolio_opening_tax_lots');
        Schema::dropIfExists('portfolio_tax_rule_versions');
        Schema::dropIfExists('portfolio_analysis_preferences');
        Schema::dropIfExists('portfolio_benchmarks');
    }
};
