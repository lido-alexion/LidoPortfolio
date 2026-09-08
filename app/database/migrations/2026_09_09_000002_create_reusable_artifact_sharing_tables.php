<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_artifact_share_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('grant_uuid')->unique();
            $table->foreignId('artifact_version_id')->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->json('dependency_version_ids_json')->nullable();
            $table->string('status', 24);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['artifact_version_id', 'recipient_user_id'], 'artifact_share_version_recipient_uq');
            $table->index(['recipient_user_id', 'status'], 'artifact_share_recipient_status_idx');
        });

        Schema::create('portfolio_artifact_library_adoptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->restrictOnDelete();
            $table->foreignId('artifact_version_id')->constrained('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->foreignId('share_grant_id')->nullable()->constrained('portfolio_artifact_share_grants')->nullOnDelete();
            $table->json('provenance_json');
            $table->timestamp('adopted_at');
            $table->timestamps();

            $table->unique(['user_id', 'artifact_version_id'], 'artifact_library_adoption_user_version_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_artifact_library_adoptions');
        Schema::dropIfExists('portfolio_artifact_share_grants');
    }
};
