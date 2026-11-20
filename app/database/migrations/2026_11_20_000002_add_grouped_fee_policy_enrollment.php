<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portfolio_transactions', 'grouped_fee_policy_enrolled')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $table->boolean('grouped_fee_policy_enrolled')->default(false)->after('dp_policy_enrolled');
                $table->index(['profile_id', 'transaction_date', 'grouped_fee_policy_enrolled'], 'pt_group_fee_profile_date_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_transactions', 'grouped_fee_policy_enrolled')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $table->dropIndex('pt_group_fee_profile_date_idx');
                $table->dropColumn('grouped_fee_policy_enrolled');
            });
        }
    }
};
