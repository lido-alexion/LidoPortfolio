<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_screener_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('reusable_artifact_version_id')->nullable();
            $table->unsignedBigInteger('artifact_binding_revision_id')->nullable();
            $table->foreign('reusable_artifact_version_id', 'screener_run_artifact_version_fk')
                ->references('id')->on('portfolio_reusable_artifact_versions')->restrictOnDelete();
            $table->foreign('artifact_binding_revision_id', 'screener_run_binding_revision_fk')
                ->references('id')->on('portfolio_artifact_binding_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_screener_runs', function (Blueprint $table) {
            $table->dropForeign('screener_run_binding_revision_fk');
            $table->dropForeign('screener_run_artifact_version_fk');
            $table->dropColumn(['artifact_binding_revision_id', 'reusable_artifact_version_id']);
        });
    }
};
