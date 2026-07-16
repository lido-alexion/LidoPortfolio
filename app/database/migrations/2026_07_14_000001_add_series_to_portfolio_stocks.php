<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_stocks')) {
            return;
        }

        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_stocks', 'series')) {
                $table->string('series', 8)->nullable()->after('exchange');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_stocks')) {
            return;
        }

        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_stocks', 'series')) {
                $table->dropColumn('series');
            }
        });
    }
};
