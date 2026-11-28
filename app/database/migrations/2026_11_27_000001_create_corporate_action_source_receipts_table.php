<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_data_quality_issues', function (Blueprint $table) {
            $table->char('manual_preview_token', 64)->nullable();
            $table->unique('manual_preview_token', 'pdqi_manual_preview_token_uq');
        });
        Schema::create('portfolio_corporate_action_source_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->nullable()->constrained('portfolio_data_quality_issues')->nullOnDelete();
            $table->string('source_kind', 40);
            $table->string('source_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type', 255)->nullable();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('body_bytes');
            $table->longText('body_base64');
            $table->json('metadata')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['issue_id', 'captured_at'], 'pcasr_issue_captured_idx');
            $table->index(['source_kind', 'sha256'], 'pcasr_kind_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_corporate_action_source_receipts');
        Schema::table('portfolio_data_quality_issues', function (Blueprint $table) {
            $table->dropUnique('pdqi_manual_preview_token_uq');
            $table->dropColumn('manual_preview_token');
        });
    }
};
