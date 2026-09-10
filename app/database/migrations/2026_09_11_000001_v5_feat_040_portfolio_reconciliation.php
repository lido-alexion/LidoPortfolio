<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_profiles', function (Blueprint $table): void {
            $table->string('reconciliation_holdings_status', 24)->default('unknown')->after('execution_mode');
            $table->string('reconciliation_funds_status', 24)->default('unknown')->after('reconciliation_holdings_status');
            $table->string('reconciliation_overall_status', 24)->default('unknown')->after('reconciliation_funds_status');
            $table->boolean('execution_blocked_by_reconciliation')->default(false)->after('reconciliation_overall_status');
            $table->timestamp('last_successful_reconciliation_at')->nullable()->after('execution_blocked_by_reconciliation');
            $table->text('last_reconciliation_failure')->nullable()->after('last_successful_reconciliation_at');
        });

        Schema::create('portfolio_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('trigger', 32);
            $table->string('status', 24);
            $table->string('holdings_status', 24)->default('unknown');
            $table->string('funds_status', 24)->default('unknown');
            $table->string('overall_status', 24)->default('unknown');
            $table->json('broker_snapshot')->nullable();
            $table->json('stox_snapshot')->nullable();
            $table->json('tolerances')->nullable();
            $table->json('discrepancies')->nullable();
            $table->json('unsupported_instruments')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'completed_at'], 'reconciliation_profile_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_reconciliation_runs');
        Schema::table('portfolio_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'reconciliation_holdings_status', 'reconciliation_funds_status', 'reconciliation_overall_status',
                'execution_blocked_by_reconciliation', 'last_successful_reconciliation_at', 'last_reconciliation_failure',
            ]);
        });
    }
};
