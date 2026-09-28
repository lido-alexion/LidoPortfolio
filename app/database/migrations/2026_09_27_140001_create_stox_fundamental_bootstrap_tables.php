<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_fundamental_bootstrap_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger', 32)->default('manual');
            $table->string('scope', 64);
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('requested')->default(0);
            $table->unsignedInteger('queued')->default(0);
            $table->unsignedInteger('running')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('summary_json')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by_user_id')->references('id')->on('portfolio_users')->nullOnDelete();
            $table->index(['status', 'created_at'], 'stox_fund_bootstrap_runs_status_idx');
        });

        Schema::create('stox_fundamental_bootstrap_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('stox_fundamental_bootstrap_runs')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('quarterly_status', 32)->nullable();
            $table->string('annual_status', 32)->nullable();
            $table->date('earliest_period')->nullable();
            $table->date('latest_period')->nullable();
            $table->unsignedInteger('facts_inserted')->default(0);
            $table->unsignedInteger('facts_upgraded')->default(0);
            $table->unsignedInteger('facts_deduped')->default(0);
            $table->unsignedInteger('facts_rejected')->default(0);
            $table->unsignedInteger('anomalies_count')->default(0);
            $table->json('coverage_json')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'stock_id'], 'stox_fund_bootstrap_jobs_run_stock_uq');
            $table->index(['status', 'id'], 'stox_fund_bootstrap_jobs_status_idx');
            $table->index(['stock_id', 'status'], 'stox_fund_bootstrap_jobs_stock_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_fundamental_bootstrap_jobs');
        Schema::dropIfExists('stox_fundamental_bootstrap_runs');
    }
};
