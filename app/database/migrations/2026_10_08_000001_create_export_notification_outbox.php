<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_export_notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained('portfolio_export_artifacts')->cascadeOnDelete();
            $table->string('event_type', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['artifact_id', 'event_type'], 'export_notification_artifact_event_uq');
            $table->index(['delivered_at', 'next_attempt_at', 'last_dispatched_at'], 'export_notification_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_export_notification_outbox');
    }
};
