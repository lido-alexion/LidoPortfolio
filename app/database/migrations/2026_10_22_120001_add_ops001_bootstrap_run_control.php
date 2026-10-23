<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_bootstrap_runs', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->unique('stox_fund_bootstrap_idempotency_uq');
            $table->json('request_snapshot')->nullable();
            $table->string('trigger_actor', 255)->nullable();
            $table->text('operator_reason')->nullable();
        });

        Schema::create('stox_fund_bootstrap_control', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('active_run_id')->nullable();
            $table->timestamps();
            $table->foreign('active_run_id', 'stox_fund_bootstrap_active_fk')
                ->references('id')->on('stox_fundamental_bootstrap_runs')->nullOnDelete();
        });
        DB::table('stox_fund_bootstrap_control')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_fund_bootstrap_control');
        Schema::table('stox_fundamental_bootstrap_runs', function (Blueprint $table): void {
            $table->dropUnique('stox_fund_bootstrap_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot', 'trigger_actor', 'operator_reason']);
        });
    }
};
