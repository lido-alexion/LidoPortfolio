<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_notification_sources', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type', 128);
            $table->string('condition_key', 191)->nullable();
            $table->string('active_condition_key', 191)->nullable()->unique('notification_source_active_condition_uq');
            $table->string('audience', 16);
            $table->string('severity', 24);
            $table->string('condition_state', 16);
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable();
            $table->json('primary_action')->nullable();
            $table->boolean('external_info_delivery')->default(false);
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('latest_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['condition_key', 'condition_state'], 'notification_source_condition_idx');
            $table->index(['notification_type', 'latest_detected_at'], 'notification_source_type_activity_idx');
        });

        Schema::create('portfolio_recipient_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('portfolio_notification_sources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('attention_state', 16)->default('unread');
            $table->string('condition_state', 16);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('latest_activity_at');
            $table->timestamp('last_successful_external_delivery_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'user_id'], 'recipient_notification_source_user_uq');
            $table->index(['user_id', 'attention_state', 'latest_activity_at'], 'recipient_notification_unread_idx');
            $table->index(['user_id', 'condition_state', 'latest_activity_at'], 'recipient_notification_condition_idx');
        });

        Schema::create('portfolio_notification_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('portfolio_notification_sources')->cascadeOnDelete();
            $table->string('activity_type', 32);
            $table->string('severity', 24);
            $table->json('snapshot')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['source_id', 'occurred_at'], 'notification_occurrence_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_notification_occurrences');
        Schema::dropIfExists('portfolio_recipient_notifications');
        Schema::dropIfExists('portfolio_notification_sources');
    }
};
