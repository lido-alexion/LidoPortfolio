<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_ai_assistant_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inference_event_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('helpful');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['inference_event_id', 'user_id'], 'ai_feedback_answer_user_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('stox_ai_assistant_feedback'); }
};
