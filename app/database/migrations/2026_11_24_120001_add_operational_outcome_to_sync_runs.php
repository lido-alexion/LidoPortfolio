<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_sync_runs', function (Blueprint $table): void {
            $table->string('operational_outcome', 40)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('operational_outcome');
        });
    }
};
