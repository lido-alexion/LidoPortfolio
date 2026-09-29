<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_user_onboarding_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('tour_version', 64);
            $table->unsignedSmallInteger('welcome_prompt_count')->default(0);
            $table->timestamp('permanently_dismissed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('current_step_id', 64)->nullable();
            $table->boolean('tour_in_progress')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('portfolio_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_user_onboarding_state');
    }
};
