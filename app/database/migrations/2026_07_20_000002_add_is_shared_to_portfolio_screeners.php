<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('is_enabled');
            $table->index(['is_shared', 'profile_id'], 'portfolio_screeners_shared_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            $table->dropIndex('portfolio_screeners_shared_profile_idx');
            $table->dropColumn('is_shared');
        });
    }
};
