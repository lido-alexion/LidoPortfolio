<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_help_feedback_aggregates', function (Blueprint $table) {
            $table->id();
            $table->string('topic_id', 32);
            $table->date('event_date');
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('not_helpful_count')->default(0);
            $table->timestamps();
            $table->unique(['topic_id', 'event_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('portfolio_help_feedback_aggregates'); }
};
