<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_reusable_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('artifact_uuid')->unique();
            $table->foreignId('owner_user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->string('artifact_type', 32);
            $table->string('slug', 120);
            $table->string('name', 200);
            $table->string('origin', 32);
            $table->json('provenance_json')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_user_id', 'artifact_type', 'slug'], 'reusable_artifacts_owner_type_slug_uq');
            $table->index(['owner_user_id', 'archived_at'], 'reusable_artifacts_library_idx');
        });

        Schema::create('portfolio_reusable_artifact_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained('portfolio_reusable_artifacts')->restrictOnDelete();
            $table->string('semver', 64);
            $table->string('status', 24);
            $table->unsignedTinyInteger('draft_slot')->nullable();
            $table->json('content_json');
            $table->json('documentation_json')->nullable();
            $table->string('definition_hash', 80);
            $table->string('change_summary', 1000)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by_user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['artifact_id', 'semver'], 'reusable_artifact_versions_semver_uq');
            $table->unique(['artifact_id', 'draft_slot'], 'reusable_artifact_versions_one_draft_uq');
            $table->index(['artifact_id', 'status'], 'reusable_artifact_versions_status_idx');
        });

        Schema::create('portfolio_reusable_artifact_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_version_id')->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->string('kind', 48);
            $table->foreignId('target_artifact_version_id')->nullable()->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->string('indicator_id', 120)->nullable();
            $table->string('indicator_version', 64)->nullable();
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->index(['source_version_id', 'kind'], 'reusable_artifact_dependencies_source_idx');
            $table->index('target_artifact_version_id', 'reusable_artifact_dependencies_target_idx');
            $table->index(['indicator_id', 'indicator_version'], 'reusable_artifact_dependencies_indicator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_reusable_artifact_dependencies');
        Schema::dropIfExists('portfolio_reusable_artifact_versions');
        Schema::dropIfExists('portfolio_reusable_artifacts');
    }
};
