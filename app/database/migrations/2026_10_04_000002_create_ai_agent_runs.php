<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_ai_agent_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('profile_id')->index();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->json('scopes');
            $table->string('delegation_digest', 64);
            $table->timestamp('delegation_expires_at');
            $table->text('objective');
            $table->string('status', 32);
            $table->json('plan')->nullable();
            $table->string('plan_hash', 64)->nullable();
            $table->json('preview')->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->json('steps')->nullable();
            $table->json('trace')->nullable();
            $table->text('answer')->nullable();
            $table->string('correlation_id', 160)->nullable();
            $table->timestamps();
        });
        foreach (['agent_planning' => 'Plan the minimum authorized tools using the declared schema. Read current state before proposing mutations. Never expose private reasoning or broker tools.', 'agent_synthesis' => 'Interpret only supplied deterministic StoX evidence. Disclose all missing evidence. Never invent measured values or claim unexecuted mutations.'] as $id => $template) {
            \Illuminate\Support\Facades\DB::table('stox_ai_capabilities')->insert(['capability_id' => $id, 'owner' => 'V9-AI-002', 'enabled' => true, 'path_order' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            \Illuminate\Support\Facades\DB::table('stox_ai_prompts')->insert(['prompt_id' => $id, 'version' => 1, 'template' => $template, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    public function down(): void { Schema::dropIfExists('stox_ai_agent_runs'); \Illuminate\Support\Facades\DB::table('stox_ai_prompts')->whereIn('prompt_id', ['agent_planning', 'agent_synthesis'])->delete(); \Illuminate\Support\Facades\DB::table('stox_ai_capabilities')->whereIn('capability_id', ['agent_planning', 'agent_synthesis'])->delete(); }
};
