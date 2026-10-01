<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_api_failure_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('environment', 32);
            $table->string('direction', 32);
            $table->string('component', 160)->nullable();
            $table->string('method', 12)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('error_category', 80)->nullable();
            $table->text('safe_message')->nullable();
            $table->string('trace_id', 128)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedBigInteger('occurrence_count')->default(1);
            $table->unsignedBigInteger('github_issue_number')->nullable();
            $table->string('github_issue_url', 500)->nullable();
            $table->string('github_issue_state', 24)->nullable();
            $table->string('sync_status', 32)->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_github_checked_at')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->timestamps();

            $table->index(['environment', 'last_seen_at']);
            $table->index(['sync_status', 'last_github_checked_at'], 'api_failure_sync_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_api_failure_incidents');
    }
};
