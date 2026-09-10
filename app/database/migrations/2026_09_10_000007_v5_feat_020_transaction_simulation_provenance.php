<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->string('simulation_origin', 32)->nullable()->after('source');
            $table->date('simulation_effective_session_date')->nullable()->after('simulation_origin');
            $table->timestamp('simulation_processed_at')->nullable()->after('simulation_effective_session_date');
            $table->json('simulation_evidence')->nullable()->after('simulation_processed_at');
            $table->index(['profile_id', 'simulation_origin'], 'transaction_profile_sim_origin_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropIndex('transaction_profile_sim_origin_idx');
            $table->dropColumn([
                'simulation_origin', 'simulation_effective_session_date',
                'simulation_processed_at', 'simulation_evidence',
            ]);
        });
    }
};
