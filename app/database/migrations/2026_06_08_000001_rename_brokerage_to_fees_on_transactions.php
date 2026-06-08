<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('portfolio_transactions', 'brokerage')
            && ! Schema::hasColumn('portfolio_transactions', 'fees')) {
            DB::statement('ALTER TABLE portfolio_transactions CHANGE brokerage fees DECIMAL(18,4) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_transactions', 'fees')
            && ! Schema::hasColumn('portfolio_transactions', 'brokerage')) {
            DB::statement('ALTER TABLE portfolio_transactions CHANGE fees brokerage DECIMAL(18,4) NOT NULL DEFAULT 0');
        }
    }
};
