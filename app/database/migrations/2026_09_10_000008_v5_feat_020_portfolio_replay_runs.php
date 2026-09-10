<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_replay_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('starting_mode', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('starting_cash', 18, 4)->nullable();
            $table->string('price_method', 32);
            $table->decimal('adverse_slippage_percent', 8, 4)->default(0);
            $table->string('status', 24)->default('queued');
            $table->date('checkpoint_date')->nullable();
            $table->json('pinned_world');
            $table->json('starting_state');
            $table->json('readiness');
            $table->json('results')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'status'], 'replay_profile_status_idx');
        });

        Schema::create('portfolio_replay_run_tombstones', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('portfolio_profiles')->nullOnDelete();
            $table->string('run_type', 24)->default('portfolio_replay');
            $table->string('final_status', 24);
            $table->timestamp('run_created_at');
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->timestamp('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_replay_run_tombstones');
        Schema::dropIfExists('portfolio_replay_runs');
    }
};
