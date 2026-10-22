<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_bootstrap_projection_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->unique();
            $table->unsignedBigInteger('last_job_id')->default(0);
            $table->timestamp('reconciliation_completed_at')->nullable();
            $table->timestamp('outcomes_completed_at')->nullable();
            $table->json('outcomes_json')->nullable();
            $table->timestamps();
            $table->foreign('run_id', 'stox_bootstrap_projection_run_fk')
                ->references('id')->on('stox_fundamental_bootstrap_runs')->cascadeOnDelete();
        });

        Schema::create('stox_bootstrap_occurrence_projection_checks', function (Blueprint $table): void {
            $table->unsignedBigInteger('occurrence_id')->primary();
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->foreign('occurrence_id', 'stox_bootstrap_occurrence_projection_fk')
                ->references('id')->on('stox_bootstrap_schedule_occurrences')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_bootstrap_occurrence_projection_checks');
        Schema::dropIfExists('stox_bootstrap_projection_receipts');
    }
};
