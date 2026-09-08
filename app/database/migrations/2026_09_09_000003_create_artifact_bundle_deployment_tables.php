<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_artifact_bundle_deployments', function (Blueprint $table) {
            $table->id();
            $table->uuid('deployment_uuid')->unique();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->restrictOnDelete();
            $table->foreignId('bundle_version_id')->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->string('status', 24);
            $table->json('plan_json');
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status'], 'artifact_bundle_deployments_profile_status_idx');
        });

        Schema::create('portfolio_artifact_bundle_deployment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('portfolio_artifact_bundle_deployments')->restrictOnDelete();
            $table->foreignId('member_version_id')->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->string('action', 24);
            $table->foreignId('binding_id')->nullable()->constrained('portfolio_artifact_bindings')->restrictOnDelete();
            $table->unsignedBigInteger('previous_revision_id')->nullable();
            $table->unsignedBigInteger('resulting_revision_id')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'member_version_id'], 'artifact_bundle_deployment_member_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_artifact_bundle_deployment_items');
        Schema::dropIfExists('portfolio_artifact_bundle_deployments');
    }
};
