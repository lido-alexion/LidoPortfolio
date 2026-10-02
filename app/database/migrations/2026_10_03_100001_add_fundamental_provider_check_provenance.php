<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_provider_checks', function (Blueprint $table): void {
            $table->string('requested_symbol', 64)->nullable()->after('response_hash');
            $table->string('provider_symbol', 64)->nullable()->after('requested_symbol');
            $table->json('source_meta')->nullable()->after('provider_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('stox_fundamental_provider_checks', function (Blueprint $table): void {
            $table->dropColumn(['requested_symbol', 'provider_symbol', 'source_meta']);
        });
    }
};
