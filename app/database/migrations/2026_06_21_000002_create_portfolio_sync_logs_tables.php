<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('job_name', 64);
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('stocks_processed')->nullable();
            $table->unsignedInteger('failures')->nullable();
            $table->unsignedInteger('skipped')->nullable();
            $table->text('summary')->nullable();
            $table->index(['job_name', 'started_at']);
        });

        Schema::create('portfolio_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->string('job_name', 64);
            $table->string('level', 16);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at');
            $table->index('run_id');
            $table->index(['level', 'logged_at']);
            $table->index('logged_at');
            $table->foreign('run_id')->references('id')->on('portfolio_sync_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_sync_logs');
        Schema::dropIfExists('portfolio_sync_runs');
    }
};
