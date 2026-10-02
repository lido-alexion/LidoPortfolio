<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_bootstrap_jobs', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable()->after('completed_at');
            $table->index(['status', 'next_attempt_at', 'id'], 'stox_fund_bootstrap_retry_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stox_fundamental_bootstrap_jobs', function (Blueprint $table): void {
            $table->dropIndex('stox_fund_bootstrap_retry_due_idx');
            $table->dropColumn('next_attempt_at');
        });
    }
};
