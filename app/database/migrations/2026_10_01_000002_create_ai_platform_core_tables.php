<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_ai_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->string('capability_id')->unique();
            $table->string('owner');
            $table->json('path_order')->nullable();
            $table->json('output_schema')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('stox_ai_provider_paths', function (Blueprint $table): void {
            $table->id();
            $table->string('path_id')->unique();
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('priority')->default(0);
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('stox_ai_prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('prompt_id');
            $table->unsignedInteger('version');
            $table->longText('template');
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['prompt_id', 'version']);
        });

        Schema::create('stox_ai_budget_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->unique();
            $table->decimal('soft_limit', 18, 8)->nullable();
            $table->decimal('hard_limit', 18, 8)->nullable();
            $table->decimal('spent', 18, 8)->default(0);
            $table->string('period')->default('monthly');
            $table->timestamp('period_started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stox_ai_inference_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->index();
            $table->string('capability_id')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('outcome');
            $table->string('error_code')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->json('routing_trace')->nullable();
            $table->string('trace_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ai_inference_events');
        Schema::dropIfExists('stox_ai_budget_limits');
        Schema::dropIfExists('stox_ai_prompts');
        Schema::dropIfExists('stox_ai_provider_paths');
        Schema::dropIfExists('stox_ai_capabilities');
    }
};
