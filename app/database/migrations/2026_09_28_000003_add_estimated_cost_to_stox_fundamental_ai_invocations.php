<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_ai_invocations', function (Blueprint $table): void {
            $table->decimal('estimated_cost_usd', 12, 6)->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('stox_fundamental_ai_invocations', function (Blueprint $table): void {
            $table->dropColumn('estimated_cost_usd');
        });
    }
};
