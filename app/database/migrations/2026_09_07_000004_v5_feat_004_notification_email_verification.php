<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_notification_email_destinations', function (Blueprint $table) {
            $table->string('verification_token_hash', 64)->nullable()->after('verified_at');
            $table->timestamp('verification_expires_at')->nullable()->after('verification_token_hash');
            $table->index('verification_token_hash', 'notification_email_verification_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_notification_email_destinations', function (Blueprint $table) {
            $table->dropIndex('notification_email_verification_idx');
            $table->dropColumn(['verification_token_hash', 'verification_expires_at']);
        });
    }
};
