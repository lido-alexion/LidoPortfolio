<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_notification_id')->constrained('portfolio_recipient_notifications')->cascadeOnDelete();
            $table->foreignId('channel_setting_id')->nullable()->constrained('portfolio_notification_channel_settings')->nullOnDelete();
            $table->string('channel', 24);
            $table->string('delivery_kind', 24);
            $table->text('destination');
            $table->string('destination_hash', 64);
            $table->string('status', 24)->default('queued');
            $table->string('idempotency_key', 191)->unique('notification_delivery_idempotency_uq');
            $table->timestamp('available_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'notification_delivery_ready_idx');
            $table->index(['recipient_notification_id', 'channel'], 'notification_delivery_recipient_idx');
        });

        Schema::create('portfolio_notification_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('portfolio_notification_deliveries')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('status', 24);
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->unique(['delivery_id', 'attempt_number'], 'notification_delivery_attempt_number_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_notification_delivery_attempts');
        Schema::dropIfExists('portfolio_notification_deliveries');
    }
};
