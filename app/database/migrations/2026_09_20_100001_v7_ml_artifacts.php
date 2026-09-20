<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_model_versions', function (Blueprint $table): void {
            $table->string('artifact_path', 512)->nullable()->after('status');
            $table->string('artifact_sha256', 64)->nullable()->after('artifact_path');
            $table->string('artifact_format', 32)->nullable()->after('artifact_sha256');
            $table->string('artifact_version', 32)->nullable()->after('artifact_format');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_model_versions', function (Blueprint $table): void {
            $table->dropColumn(['artifact_path', 'artifact_sha256', 'artifact_format', 'artifact_version']);
        });
    }
};
