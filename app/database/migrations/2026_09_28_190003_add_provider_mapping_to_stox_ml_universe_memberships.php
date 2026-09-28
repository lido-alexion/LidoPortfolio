<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_ml_universe_memberships', function (Blueprint $table): void {
            $table->string('provider_symbol', 64)->nullable()->after('snapshot_key');
            $table->string('provider_token', 128)->nullable()->after('provider_symbol');
            $table->string('exchange', 16)->nullable()->after('provider_token');
            $table->index(['provider_token', 'effective_from'], 'stox_ml_membership_provider_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_universe_memberships', function (Blueprint $table): void {
            $table->dropIndex('stox_ml_membership_provider_token_idx');
            $table->dropColumn(['provider_symbol', 'provider_token', 'exchange']);
        });
    }
};
