<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table stored only the retired FEAT-063 collector's operator state.
        foreach (['stox_microstructure_collector_state', 'portfolio_microstructure_collector_state'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stox_microstructure_collector_state')) {
            return;
        }

        Schema::create('stox_microstructure_collector_state', function (Blueprint $table) {
            $table->id();
            $table->boolean('manual_hold')->default(false);
            $table->unsignedBigInteger('manual_hold_by_user_id')->nullable();
            $table->timestamp('manual_hold_at')->nullable();
            $table->string('last_command', 64)->nullable();
            $table->timestamp('last_command_at')->nullable();
            $table->unsignedBigInteger('last_command_by_user_id')->nullable();
            $table->timestamp('universe_refreshed_at')->nullable();
            $table->timestamps();

            $table->foreign('manual_hold_by_user_id', 'ms_collector_hold_user_fk')
                ->references('id')->on('portfolio_users')->nullOnDelete();
            $table->foreign('last_command_by_user_id', 'ms_collector_cmd_user_fk')
                ->references('id')->on('portfolio_users')->nullOnDelete();
        });

        DB::table('stox_microstructure_collector_state')->insert([
            'manual_hold' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
