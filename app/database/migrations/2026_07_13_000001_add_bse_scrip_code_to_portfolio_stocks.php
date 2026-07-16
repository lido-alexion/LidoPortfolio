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
            if (! Schema::hasColumn('portfolio_stocks', 'bse_scrip_code')) {
                $table->string('bse_scrip_code', 16)->nullable()->after('isin');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_stocks')) {
            return;
        }

        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_stocks', 'bse_scrip_code')) {
                $table->dropColumn('bse_scrip_code');
            }
        });
    }
};
