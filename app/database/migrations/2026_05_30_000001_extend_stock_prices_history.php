<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_stock_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_stock_prices', 'adjusted_close_price')) {
                $table->decimal('adjusted_close_price', 18, 4)->nullable()->after('close_price');
            }
            if (! Schema::hasColumn('portfolio_stock_prices', 'provider_source')) {
                $table->string('provider_source')->nullable()->after('volume');
            }
        });

        if (Schema::hasColumn('portfolio_stock_prices', 'data_source')) {
            DB::table('portfolio_stock_prices')
                ->whereNull('provider_source')
                ->update(['provider_source' => DB::raw('data_source')]);
        }

        DB::table('portfolio_stock_prices')
            ->whereNull('adjusted_close_price')
            ->update(['adjusted_close_price' => DB::raw('close_price')]);
    }

    public function down(): void
    {
        Schema::table('portfolio_stock_prices', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_stock_prices', 'adjusted_close_price')) {
                $table->dropColumn('adjusted_close_price');
            }
            if (Schema::hasColumn('portfolio_stock_prices', 'provider_source')) {
                $table->dropColumn('provider_source');
            }
        });
    }
};
