<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->decimal('realized_pl', 18, 4)->nullable()->after('fees');
            $table->decimal('squared_off_fees', 18, 4)->nullable()->after('realized_pl');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropColumn(['realized_pl', 'squared_off_fees']);
        });
    }
};
