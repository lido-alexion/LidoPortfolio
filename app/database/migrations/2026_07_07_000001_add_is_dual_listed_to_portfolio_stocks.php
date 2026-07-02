<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_stocks', 'is_dual_listed')) {
                $table->boolean('is_dual_listed')->default(false)->after('is_benchmark');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('portfolio_stocks', function (Blueprint $table) {
                $table->index('isin', 'portfolio_stocks_isin_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('portfolio_stocks', function (Blueprint $table) {
                $table->dropIndex('portfolio_stocks_isin_idx');
            });
        }

        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_stocks', 'is_dual_listed')) {
                $table->dropColumn('is_dual_listed');
            }
        });
    }
};
