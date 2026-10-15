<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_dashboard_layouts', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('schema_version');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_dashboard_layouts', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
