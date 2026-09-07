<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_notification_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->boolean('enabled')->default(false);
            $table->text('configuration')->nullable();
            $table->string('health_status', 24)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel'], 'notification_channel_user_channel_uq');
            $table->index(['channel', 'enabled', 'health_status'], 'notification_channel_delivery_idx');
        });

        Schema::create('portfolio_notification_email_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('email');
            $table->boolean('is_account_email')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email'], 'notification_email_user_email_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_notification_email_destinations');
        Schema::dropIfExists('portfolio_notification_channel_settings');
    }
};
