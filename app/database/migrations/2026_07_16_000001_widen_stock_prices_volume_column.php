<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_stock_prices')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE portfolio_stock_prices MODIFY volume BIGINT UNSIGNED NULL');

            return;
        }

        Schema::table('portfolio_stock_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('volume')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_stock_prices')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE portfolio_stock_prices MODIFY volume INT UNSIGNED NULL');

            return;
        }

        Schema::table('portfolio_stock_prices', function (Blueprint $table) {
            $table->unsignedInteger('volume')->nullable()->change();
        });
    }
};
