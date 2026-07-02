<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_operational_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_operational_alerts', 'manually_cleared_at')) {
                $table->timestamp('manually_cleared_at')->nullable()->after('acknowledged_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_operational_alerts', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_operational_alerts', 'manually_cleared_at')) {
                $table->dropColumn('manually_cleared_at');
            }
        });
    }
};
