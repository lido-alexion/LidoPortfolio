<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_universe_memberships', function (Blueprint $table): void {
            $table->string('taxonomy_version', 64)->nullable()->after('sector_snapshot');
            $table->timestamp('classification_available_at')->nullable()->after('taxonomy_version');
            $table->string('classification_revision_hash', 64)->nullable()->after('classification_available_at');
            $table->index(['universe_key', 'taxonomy_version', 'effective_from'], 'stox_ml_membership_sector_prov_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_universe_memberships', function (Blueprint $table): void {
            $table->dropIndex('stox_ml_membership_sector_prov_idx');
            $table->dropColumn(['taxonomy_version', 'classification_available_at', 'classification_revision_hash']);
        });
    }
};
