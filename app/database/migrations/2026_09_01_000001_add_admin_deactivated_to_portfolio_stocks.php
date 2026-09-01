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
            if (! Schema::hasColumn('portfolio_stocks', 'admin_deactivated')) {
                $table->boolean('admin_deactivated')->default(false)->after('is_active');
                $table->index('admin_deactivated', 'portfolio_stocks_admin_deactivated_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_stocks')) {
            return;
        }

        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_stocks', 'admin_deactivated')) {
                $table->dropIndex('portfolio_stocks_admin_deactivated_idx');
                $table->dropColumn('admin_deactivated');
            }
        });
    }
};
