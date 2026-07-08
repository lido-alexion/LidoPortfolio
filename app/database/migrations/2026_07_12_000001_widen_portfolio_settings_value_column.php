<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_settings')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE portfolio_settings MODIFY setting_value LONGTEXT NULL');

            return;
        }

        Schema::table('portfolio_settings', function (Blueprint $table) {
            $table->longText('setting_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_settings')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE portfolio_settings MODIFY setting_value TEXT NULL');

            return;
        }

        Schema::table('portfolio_settings', function (Blueprint $table) {
            $table->text('setting_value')->nullable()->change();
        });
    }
};
