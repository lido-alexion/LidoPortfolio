<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portfolio_transactions', 'dp_fee_amount')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $table->decimal('dp_fee_amount', 18, 4)->default(0)->after('fees');
            });
        }
        if (! Schema::hasColumn('portfolio_transactions', 'dp_policy_enrolled')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $table->boolean('dp_policy_enrolled')->default(false)->after('dp_fee_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_transactions', 'dp_policy_enrolled')) {
            Schema::table('portfolio_transactions', fn (Blueprint $table) => $table->dropColumn('dp_policy_enrolled'));
        }
        if (Schema::hasColumn('portfolio_transactions', 'dp_fee_amount')) {
            Schema::table('portfolio_transactions', fn (Blueprint $table) => $table->dropColumn('dp_fee_amount'));
        }
    }
};
