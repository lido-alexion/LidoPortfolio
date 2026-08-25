<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §34.4 / OD-12 — persist position target_amount and filled_amount on strategy-owned holdings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_holdings', function (Blueprint $table) {
            $table->decimal('target_amount', 18, 4)->nullable()->after('invested_amount');
            $table->decimal('filled_amount', 18, 4)->nullable()->after('target_amount');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_holdings', function (Blueprint $table) {
            $table->dropColumn(['target_amount', 'filled_amount']);
        });
    }
};
