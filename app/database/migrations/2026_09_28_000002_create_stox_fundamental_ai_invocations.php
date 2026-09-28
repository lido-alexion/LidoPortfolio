<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_fundamental_ai_invocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('stock_id')->nullable()->index();
            $table->string('feature_key', 64)->default('fundamental_signals');
            $table->string('provider', 32)->nullable();
            $table->string('provider_role', 16)->nullable();
            $table->string('status', 32);
            $table->json('failover_from')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('prompt_version', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_fundamental_ai_invocations');
    }
};
