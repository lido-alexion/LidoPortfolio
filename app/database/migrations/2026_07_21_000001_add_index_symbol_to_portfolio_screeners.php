<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            $table->string('index_symbol', 32)->nullable()->after('watchlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            $table->dropColumn('index_symbol');
        });
    }
};
