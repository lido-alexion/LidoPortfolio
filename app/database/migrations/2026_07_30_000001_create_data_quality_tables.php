<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained('portfolio_stocks')->nullOnDelete();
            $table->string('symbol', 30)->nullable();
            $table->string('issue_type', 60);
            $table->string('issue_status', 30)->default('pending_review');
            $table->string('detection_method', 80);
            $table->string('detection_source', 120)->nullable();
            $table->decimal('suggested_ratio', 12, 6)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('corporate_action_type', 60)->nullable();
            $table->date('ex_date')->nullable();
            $table->date('record_date')->nullable();
            $table->decimal('previous_close', 14, 4)->nullable();
            $table->decimal('current_open', 14, 4)->nullable();
            $table->decimal('gap_percent', 10, 4)->nullable();
            $table->decimal('gap_ratio', 12, 6)->nullable();
            $table->decimal('volume_change_percent', 10, 4)->nullable();
            $table->boolean('exchange_match')->default(false);
            $table->json('detection_payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('auto_resolved')->default(false);
            $table->decimal('applied_ratio', 12, 6)->nullable();
            $table->decimal('latest_suggested_ratio', 12, 6)->nullable();
            $table->foreignId('latest_resolution_id')->nullable();
            $table->timestamps();

            $table->index(['issue_status', 'issue_type'], 'pdqi_status_type_idx');
            $table->index(['stock_id', 'issue_status'], 'pdqi_stock_status_idx');
            $table->index(['detected_at'], 'pdqi_detected_at_idx');
        });

        Schema::create('portfolio_data_quality_issue_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')
                ->constrained('portfolio_data_quality_issues')
                ->cascadeOnDelete();
            $table->string('evidence_key', 80);
            $table->string('evidence_label', 120)->nullable();
            $table->text('evidence_value')->nullable();
            $table->json('evidence_payload')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['issue_id', 'evidence_key'], 'pdqie_issue_key_idx');
        });

        Schema::create('portfolio_data_quality_issue_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')
                ->constrained('portfolio_data_quality_issues')
                ->cascadeOnDelete();
            $table->string('resolution_type', 40);
            $table->string('resolution_status', 30);
            $table->decimal('applied_ratio', 12, 6)->nullable();
            $table->decimal('suggested_ratio_snapshot', 12, 6)->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('supersedes_resolution_id')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->index(['issue_id', 'resolved_at'], 'pdqir_issue_resolved_at_idx');
        });

        Schema::table('portfolio_data_quality_issues', function (Blueprint $table) {
            $table->foreign('latest_resolution_id', 'pdqi_latest_resolution_fk')
                ->references('id')
                ->on('portfolio_data_quality_issue_resolutions')
                ->nullOnDelete();
        });

        Schema::create('portfolio_price_adjustment_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('portfolio_data_quality_issues')->nullOnDelete();
            $table->string('factor_type', 40)->default('corporate_action');
            $table->string('action_type', 60)->nullable();
            $table->date('effective_ex_date');
            $table->decimal('applied_ratio', 12, 6);
            $table->decimal('price_divisor', 14, 6);
            $table->decimal('volume_multiplier', 14, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamp('applied_at');
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['stock_id', 'is_active', 'effective_ex_date'], 'ppaf_stock_active_ex_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_price_adjustment_factors');
        Schema::table('portfolio_data_quality_issues', function (Blueprint $table) {
            $table->dropForeign('pdqi_latest_resolution_fk');
        });
        Schema::dropIfExists('portfolio_data_quality_issue_resolutions');
        Schema::dropIfExists('portfolio_data_quality_issue_evidence');
        Schema::dropIfExists('portfolio_data_quality_issues');
    }
};
