<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_fundamental_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('quarterly_freshness_months')->default(5);
            $table->unsignedInteger('annual_freshness_months')->default(15);
            $table->unsignedInteger('request_delay_ms')->default(750);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('provider', 32)->default('yahoo');
            $table->boolean('paused')->default(false);
            $table->timestamps();
        });

        Schema::create('stox_fundamental_update_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger', 32)->default('manual');
            $table->string('scope', 64)->default('incremental');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('requested')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('stats_json')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'stox_fund_runs_status_created_idx');
        });

        Schema::create('stox_fundamental_update_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('stox_fundamental_update_runs')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('cadence', 16);
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('priority_tier')->default(8);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'stock_id', 'cadence'], 'stox_fund_jobs_run_stock_cadence_uq');
            $table->index(['status', 'priority_tier', 'next_attempt_at'], 'stox_fund_jobs_status_priority_idx');
            $table->index(['stock_id', 'cadence', 'status'], 'stox_fund_jobs_stock_cadence_idx');
        });

        Schema::create('stox_fundamental_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('statement_type', 32);
            $table->string('cadence', 16);
            $table->string('statement_basis', 24)->default('consolidated');
            $table->string('fact_key', 64);
            $table->date('period_start')->nullable();
            $table->date('period_end');
            $table->date('reported_period')->nullable();
            $table->decimal('value', 28, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->date('availability_date');
            $table->timestamp('first_fetched_at');
            $table->string('revision_hash', 64);
            $table->unsignedInteger('revision_number')->default(1);
            $table->boolean('is_current')->default(true);
            $table->json('source_meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['stock_id', 'statement_type', 'cadence', 'statement_basis', 'fact_key', 'period_end', 'revision_hash'],
                'stox_fund_facts_natural_revision_uq'
            );
            $table->index(['stock_id', 'cadence', 'fact_key', 'period_end'], 'stox_fund_facts_lookup_idx');
            $table->index(['stock_id', 'availability_date'], 'stox_fund_facts_availability_idx');
        });

        Schema::create('stox_ml_training_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('horizon', 8);
            $table->string('status', 32)->default('queued');
            $table->date('cutoff_date')->nullable();
            $table->json('configuration')->nullable();
            $table->json('metrics')->nullable();
            $table->json('baselines')->nullable();
            $table->json('selected_features')->nullable();
            $table->json('failure')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['horizon', 'status'], 'stox_ml_training_horizon_status_idx');
        });

        Schema::create('stox_ml_model_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_run_id')->nullable()->constrained('stox_ml_training_runs')->nullOnDelete();
            $table->string('horizon', 8);
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('candidate');
            $table->string('model_family', 64)->default('interpretable_logistic_baseline');
            $table->date('training_cutoff_date')->nullable();
            $table->json('feature_set');
            $table->json('preprocessing');
            $table->json('label_definition');
            $table->json('benchmark_mapping');
            $table->json('hyperparameters')->nullable();
            $table->json('evaluation_metrics')->nullable();
            $table->json('promotion_thresholds')->nullable();
            $table->json('audit_metadata')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->foreignId('promoted_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['horizon', 'version'], 'stox_ml_versions_horizon_version_uq');
            $table->index(['horizon', 'status'], 'stox_ml_versions_horizon_status_idx');
        });

        Schema::create('stox_ml_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('stox_ml_model_versions')->cascadeOnDelete();
            $table->string('horizon', 8);
            $table->timestamp('as_of');
            $table->decimal('score', 8, 4);
            $table->decimal('confidence', 8, 4);
            $table->string('benchmark_symbol', 32)->default('NIFTY50');
            $table->boolean('shadow')->default(false);
            $table->json('explanations')->nullable();
            $table->json('feature_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'model_version_id', 'as_of', 'shadow'], 'stox_ml_pred_stock_model_asof_uq');
            $table->index(['stock_id', 'horizon', 'as_of'], 'stox_ml_pred_stock_horizon_asof_idx');
        });

        Schema::create('stox_ml_drift_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_version_id')->constrained('stox_ml_model_versions')->cascadeOnDelete();
            $table->unsignedInteger('window_months');
            $table->string('status', 32)->default('ok');
            $table->json('metrics')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['model_version_id', 'window_months', 'checked_at'], 'stox_ml_drift_model_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_drift_checks');
        Schema::dropIfExists('stox_ml_predictions');
        Schema::dropIfExists('stox_ml_model_versions');
        Schema::dropIfExists('stox_ml_training_runs');
        Schema::dropIfExists('stox_fundamental_facts');
        Schema::dropIfExists('stox_fundamental_update_jobs');
        Schema::dropIfExists('stox_fundamental_update_runs');
        Schema::dropIfExists('stox_fundamental_settings');
    }
};
