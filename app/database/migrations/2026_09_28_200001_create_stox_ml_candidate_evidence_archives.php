<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_ml_candidate_evidence_archives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_version_id')
                ->unique('stox_ml_candidate_evidence_model_uq')
                ->constrained('stox_ml_model_versions')
                ->cascadeOnDelete();
            $table->string('horizon', 8);
            $table->unsignedInteger('model_version');
            $table->string('feature_set_version', 128)->nullable();
            $table->string('dataset_version', 128)->nullable();
            $table->string('artifact_path', 512)->nullable();
            $table->string('artifact_sha256', 64)->nullable();
            $table->string('evidence_sha256', 64);
            $table->json('evidence');
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->index(['horizon', 'model_version'], 'stox_ml_candidate_evidence_horizon_version_idx');
            $table->index('evidence_sha256', 'stox_ml_candidate_evidence_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_candidate_evidence_archives');
    }
};
