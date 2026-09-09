<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_analysis_preferences', function (Blueprint $table) {
            $table->string('scope_key', 64)->after('user_id');
            $table->unique(['user_id', 'scope_key'], 'analysis_preferences_user_scope_uq');
        });

        DB::table('portfolio_benchmarks')->updateOrInsert(
            ['stable_key' => 'nifty-50-tri'],
            [
                'name' => 'NIFTY 50 Total Return Index',
                'symbol' => 'NIFTY50-TRI',
                'return_type' => 'total_return',
                'currency' => 'INR',
                'provider' => 'NSE Indices',
                'provenance' => 'Official NIFTY 50 Total Return Index series; latest authoritative level on or before the requested date.',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('portfolio_benchmarks')->where('stable_key', 'nifty-50-tri')->delete();

        Schema::table('portfolio_analysis_preferences', function (Blueprint $table) {
            $table->dropUnique('analysis_preferences_user_scope_uq');
            $table->dropColumn('scope_key');
        });
    }
};
