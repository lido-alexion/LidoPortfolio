<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_microstructure_collector_state', function (Blueprint $table) {
            $table->id();
            $table->boolean('manual_hold')->default(false);
            $table->unsignedBigInteger('manual_hold_by_user_id')->nullable();
            $table->timestamp('manual_hold_at')->nullable();
            $table->string('last_command', 64)->nullable();
            $table->timestamp('last_command_at')->nullable();
            $table->unsignedBigInteger('last_command_by_user_id')->nullable();
            $table->timestamp('universe_refreshed_at')->nullable();
            $table->timestamps();

            $table->foreign('manual_hold_by_user_id', 'ms_collector_hold_user_fk')->references('id')->on('portfolio_users')->nullOnDelete();
            $table->foreign('last_command_by_user_id', 'ms_collector_cmd_user_fk')->references('id')->on('portfolio_users')->nullOnDelete();
        });

        DB::table('portfolio_microstructure_collector_state')->insert([
            'manual_hold' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_microstructure_collector_state');
    }
};
