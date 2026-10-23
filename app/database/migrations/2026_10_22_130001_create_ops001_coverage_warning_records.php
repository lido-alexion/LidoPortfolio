<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_fund_bootstrap_coverage_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('stox_fundamental_bootstrap_runs')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('metric_key', 64);
            $table->string('cadence', 16);
            $table->date('period_end')->nullable();
            $table->string('reason', 48);
            $table->string('acknowledged_reason', 48)->nullable();
            $table->string('state', 24)->default('actionable');
            $table->string('identity_hash', 64);
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_note', 1000)->nullable();
            $table->timestamp('evidence_at')->nullable();
            $table->timestamps();
            $table->foreign('acknowledged_by', 'stox_fbg_ack_user_fk')->references('id')->on('portfolio_users')->nullOnDelete();
            $table->unique('identity_hash', 'stox_fbg_identity_uq');
            $table->index(['state', 'reason', 'id'], 'stox_fbg_state_reason_idx');
            $table->index(['stock_id', 'metric_key', 'cadence', 'id'], 'stox_fbg_target_idx');
        });

        Schema::create('stox_fund_bootstrap_gap_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gap_id')->constrained('stox_fund_bootstrap_coverage_gaps')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('stox_fundamental_bootstrap_runs')->cascadeOnDelete();
            $table->timestamp('observed_at');
            $table->string('observed_reason', 48);
            $table->string('fillability_evidence', 48)->nullable();
            $table->timestamps();
            $table->unique(['gap_id', 'run_id'], 'stox_fbg_obs_gap_run_uq');
            $table->index(['run_id', 'id'], 'stox_fbg_obs_run_idx');
        });

        Schema::create('stox_fund_bootstrap_gap_ack_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gap_id')->constrained('stox_fund_bootstrap_coverage_gaps')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->string('note', 1000)->nullable();
            $table->timestamp('acknowledged_at');
            $table->foreign('actor_id', 'stox_fbg_ack_event_user_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
            $table->index(['gap_id', 'id'], 'stox_fbg_ack_event_gap_idx');
        });

        Schema::create('stox_fund_bootstrap_quality_warnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('stox_fundamental_bootstrap_runs')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('category', 48);
            $table->string('identity_hash', 64);
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->string('state', 24)->default('unresolved');
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_note', 1000)->nullable();
            $table->timestamps();
            $table->foreign('acknowledged_by', 'stox_fbw_ack_user_fk')->references('id')->on('portfolio_users')->nullOnDelete();
            $table->unique('identity_hash', 'stox_fbw_identity_uq');
            $table->index(['state', 'category', 'id'], 'stox_fbw_state_category_idx');
            $table->index(['run_id', 'stock_id'], 'stox_fbw_run_stock_idx');
        });

        Schema::create('stox_fund_bootstrap_warning_ack_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warning_id')->constrained('stox_fund_bootstrap_quality_warnings')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->string('note', 1000)->nullable();
            $table->timestamp('acknowledged_at');
            $table->foreign('actor_id', 'stox_fbw_ack_event_user_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
            $table->index(['warning_id', 'id'], 'stox_fbw_ack_event_warning_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_fund_bootstrap_warning_ack_events');
        Schema::dropIfExists('stox_fund_bootstrap_gap_ack_events');
        Schema::dropIfExists('stox_fund_bootstrap_gap_observations');
        Schema::dropIfExists('stox_fund_bootstrap_quality_warnings');
        Schema::dropIfExists('stox_fund_bootstrap_coverage_gaps');
    }
};
