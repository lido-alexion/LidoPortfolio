<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_artifact_bindings', function (Blueprint $table) {
            $table->id();
            $table->uuid('binding_uuid')->unique();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->restrictOnDelete();
            $table->foreignId('artifact_id')->constrained('portfolio_reusable_artifacts')->restrictOnDelete();
            $table->unsignedBigInteger('active_revision_id')->nullable();
            $table->string('status', 24)->default('disabled');
            $table->string('usability_state', 24)->default('usable');
            $table->json('usability_reasons_json')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['profile_id', 'artifact_id'], 'artifact_bindings_profile_artifact_uq');
            $table->index(['profile_id', 'status', 'usability_state'], 'artifact_bindings_runtime_idx');
        });

        Schema::create('portfolio_artifact_binding_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('binding_id')->constrained('portfolio_artifact_bindings')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('artifact_version_id')->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->json('settings_json')->nullable();
            $table->string('binding_status', 24);
            $table->string('usability_state', 24);
            $table->json('usability_reasons_json')->nullable();
            $table->string('action', 32);
            $table->string('change_summary', 1000)->nullable();
            $table->foreignId('activated_by_user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->timestamp('activated_at');
            $table->timestamps();

            $table->unique(['binding_id', 'revision_number'], 'artifact_binding_revisions_number_uq');
            $table->index('artifact_version_id', 'artifact_binding_revisions_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_artifact_binding_revisions');
        Schema::dropIfExists('portfolio_artifact_bindings');
    }
};
