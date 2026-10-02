<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stox_ai_capabilities', fn (Blueprint $table) => $table->unsignedInteger('max_concurrency')->default(4));
        Schema::create('stox_ai_ledger_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('stox_ai_ledger_locks')->insert(['id' => 1]);
        Schema::create('stox_ai_budget_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->string('path_id');
            $table->string('capability_id');
            $table->json('scopes');
            $table->json('pricing');
            $table->decimal('reserved_cost', 18, 8);
            $table->decimal('settled_cost', 18, 8)->nullable();
            $table->string('state', 24)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('period_started_at');
            $table->json('usage')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::table('stox_ai_capabilities', fn (Blueprint $table) => $table->dropColumn('max_concurrency'));
        Schema::dropIfExists('stox_ai_budget_reservations');
        Schema::dropIfExists('stox_ai_ledger_locks');
    }
};
