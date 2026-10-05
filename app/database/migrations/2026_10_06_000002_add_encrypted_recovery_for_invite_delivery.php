<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_user_invites', function (Blueprint $table): void {
            $table->text('token_encrypted')->nullable();
            $table->string('email_delivery_status', 24)->default('not_queued');
            $table->unsignedInteger('email_delivery_attempts')->default(0);
            $table->timestamp('email_queued_at')->nullable();
            $table->timestamp('email_accepted_at')->nullable();
            $table->string('email_last_error_code', 64)->nullable();
            $table->index(['email_delivery_status', 'email_queued_at'], 'user_invite_email_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_user_invites', function (Blueprint $table): void {
            $table->dropIndex('user_invite_email_delivery_idx');
            $table->dropColumn(['token_encrypted', 'email_delivery_status', 'email_delivery_attempts', 'email_queued_at', 'email_accepted_at', 'email_last_error_code']);
        });
    }
};
