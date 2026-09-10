<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_profiles', function (Blueprint $table) {
            $table->string('portfolio_type', 16)->default('live')->after('is_default');
            $table->string('simulation_state', 16)->nullable()->after('execution_mode');
            $table->string('simulation_price_method', 32)->nullable()->after('simulation_state');
            $table->date('simulation_checkpoint_date')->nullable()->after('simulation_price_method');
            $table->json('simulation_evidence')->nullable()->after('simulation_checkpoint_date');
            $table->index(['user_id', 'portfolio_type'], 'profile_user_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_profiles', function (Blueprint $table) {
            $table->dropIndex('profile_user_type_idx');
            $table->dropColumn([
                'portfolio_type', 'simulation_state', 'simulation_price_method',
                'simulation_checkpoint_date', 'simulation_evidence',
            ]);
        });
    }
};
