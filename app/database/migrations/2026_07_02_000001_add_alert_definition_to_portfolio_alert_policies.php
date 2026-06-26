<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_alert_policies')) {
            return;
        }

        if (Schema::hasColumn('portfolio_alert_policies', 'alert_definition')) {
            return;
        }

        Schema::table('portfolio_alert_policies', function (Blueprint $table) {
            $table->text('alert_definition')->nullable()->after('stock_universe');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_alert_policies')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_alert_policies', 'alert_definition')) {
            return;
        }

        Schema::table('portfolio_alert_policies', function (Blueprint $table) {
            $table->dropColumn('alert_definition');
        });
    }
};
