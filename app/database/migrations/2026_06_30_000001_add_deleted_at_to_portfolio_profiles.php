<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_profiles')) {
            return;
        }

        if (Schema::hasColumn('portfolio_profiles', 'deleted_at')) {
            return;
        }

        Schema::table('portfolio_profiles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_profiles')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_profiles', 'deleted_at')) {
            return;
        }

        Schema::table('portfolio_profiles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
