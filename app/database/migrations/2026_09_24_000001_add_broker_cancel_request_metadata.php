<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_orders') && ! Schema::hasColumn('portfolio_tos_orders', 'broker_cancel_requested_at')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table): void {
                $table->timestamp('broker_cancel_requested_at')->nullable()->after('last_broker_sync_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_orders') && Schema::hasColumn('portfolio_tos_orders', 'broker_cancel_requested_at')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table): void {
                $table->dropColumn('broker_cancel_requested_at');
            });
        }
    }
};
