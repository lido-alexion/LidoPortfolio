<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_stock_classification_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks', indexName: 'stox_class_obs_stock_fk')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('taxonomy_version', 64);
            $table->string('provider_sector', 160)->nullable();
            $table->string('provider_industry', 160)->nullable();
            $table->string('sector', 160)->nullable();
            $table->string('industry', 160)->nullable();
            $table->string('source_url', 512);
            $table->string('raw_evidence_sha256', 64);
            $table->json('raw_evidence')->nullable();
            $table->timestamp('first_observed_at');
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['stock_id', 'provider', 'taxonomy_version', 'raw_evidence_sha256'], 'stox_class_obs_identity_uq');
            $table->index(['stock_id', 'observed_at'], 'stox_class_obs_stock_observed_idx');
            $table->index(['sector', 'industry'], 'stox_class_obs_relationship_idx');
        });

        Schema::create('stox_stock_classification_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks', indexName: 'stox_class_override_stock_fk')->cascadeOnDelete();
            $table->string('taxonomy_version', 64);
            $table->string('sector', 160);
            $table->string('industry', 160);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('portfolio_users', indexName: 'stox_class_override_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('portfolio_users', indexName: 'stox_class_override_updated_by_fk')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['stock_id', 'removed_at'], 'stox_class_override_active_idx');
        });

        Schema::create('stox_stock_classification_override_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('override_id')->constrained('stox_stock_classification_overrides', indexName: 'stox_class_revision_override_fk')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks', indexName: 'stox_class_revision_stock_fk')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('portfolio_users', indexName: 'stox_class_revision_actor_fk')->nullOnDelete();
            $table->string('action', 32);
            $table->json('before_payload')->nullable();
            $table->json('after_payload')->nullable();
            $table->timestamp('created_at');
            $table->index(['stock_id', 'created_at'], 'stox_class_revision_stock_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_stock_classification_override_revisions');
        Schema::dropIfExists('stox_stock_classification_overrides');
        Schema::dropIfExists('stox_stock_classification_observations');
    }
};
