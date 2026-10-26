<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_bse_identity_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('plan_sha256', 64);
            $table->json('plan_json');
            $table->json('result_json');
            $table->timestamp('source_fetched_at');
            $table->timestamp('applied_at');
            $table->index('plan_sha256', 'bse_identity_audits_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_bse_identity_audits');
    }
};
