<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading Operating System entities (specs/engines/*).
 * Maps onto existing portfolio_* market/execution tables; does not duplicate securities/OHLCV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_tos_discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('dataset_version')->nullable();
            $table->string('status', 32)->default('created');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('stats_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
        });

        Schema::create('portfolio_tos_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discovery_run_id')->constrained('portfolio_tos_discovery_runs')->cascadeOnDelete();
            $table->foreignId('security_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('source', 64)->default('pattern');
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['discovery_run_id', 'security_id']);
            $table->index(['security_id']);
        });

        Schema::create('portfolio_tos_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('discovery_run_id')->nullable()->constrained('portfolio_tos_discovery_runs')->nullOnDelete();
            $table->string('status', 32)->default('created');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('stats_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
        });

        Schema::create('portfolio_tos_evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained('portfolio_tos_evaluation_runs')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('portfolio_tos_candidates')->cascadeOnDelete();
            $table->decimal('score', 12, 6)->default(0);
            $table->decimal('confidence', 8, 4)->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->json('evidence')->nullable();
            $table->json('passed_rules')->nullable();
            $table->json('failed_rules')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['evaluation_run_id', 'candidate_id']);
            $table->index(['evaluation_run_id', 'rank']);
        });

        Schema::create('portfolio_tos_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('evaluation_result_id')->nullable()->constrained('portfolio_tos_evaluation_results')->nullOnDelete();
            $table->foreignId('security_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('recommendation_type', 16);
            $table->unsignedTinyInteger('priority')->default(50);
            $table->decimal('confidence', 8, 4)->default(0);
            $table->string('risk_level', 16)->default('medium');
            $table->decimal('suggested_position_size', 18, 4)->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('evidence')->nullable();
            $table->json('failed_checks')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
            $table->index(['profile_id', 'recommendation_type']);
            $table->index(['expires_at']);
        });

        Schema::create('portfolio_tos_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('portfolio_tos_recommendations')->nullOnDelete();
            $table->string('notification_type', 64)->default('recommendation');
            $table->string('channel', 32)->default('telegram');
            $table->string('recipient')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('created');
            $table->string('idempotency_key', 128)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_key']);
            $table->index(['profile_id', 'status']);
        });

        Schema::create('portfolio_tos_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('portfolio_tos_recommendations')->nullOnDelete();
            $table->foreignId('security_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('side', 8);
            $table->decimal('quantity', 18, 4);
            $table->string('order_type', 32)->default('market');
            $table->string('status', 32)->default('created');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
        });

        Schema::create('portfolio_tos_order_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('portfolio_tos_orders')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('portfolio_transactions')->cascadeOnDelete();
            $table->decimal('execution_price', 18, 4);
            $table->decimal('quantity', 18, 4);
            $table->decimal('charges', 18, 4)->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'transaction_id']);
        });

        Schema::create('portfolio_tos_review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 32)->default('completed');
            $table->timestamp('generated_at')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'generated_at']);
        });

        Schema::create('portfolio_tos_review_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('portfolio_tos_review_reports')->cascadeOnDelete();
            $table->string('metric_name', 64);
            $table->decimal('metric_value', 18, 6)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['report_id', 'metric_name']);
        });

        Schema::create('portfolio_tos_pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('status', 32)->default('created');
            $table->json('stages_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_pipeline_runs');
        Schema::dropIfExists('portfolio_tos_review_metrics');
        Schema::dropIfExists('portfolio_tos_review_reports');
        Schema::dropIfExists('portfolio_tos_order_transactions');
        Schema::dropIfExists('portfolio_tos_orders');
        Schema::dropIfExists('portfolio_tos_notifications');
        Schema::dropIfExists('portfolio_tos_recommendations');
        Schema::dropIfExists('portfolio_tos_evaluation_results');
        Schema::dropIfExists('portfolio_tos_evaluation_runs');
        Schema::dropIfExists('portfolio_tos_candidates');
        Schema::dropIfExists('portfolio_tos_discovery_runs');
    }
};
