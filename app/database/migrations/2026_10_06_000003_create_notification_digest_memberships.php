<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_notification_digest_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users', indexName: 'notification_digest_user_fk')->restrictOnDelete();
            $table->foreignId('recipient_notification_id')->constrained('portfolio_recipient_notifications', indexName: 'notification_digest_recipient_fk')->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('portfolio_notification_deliveries', indexName: 'notification_digest_delivery_fk')->nullOnDelete();
            $table->date('digest_date');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_notification_id', 'digest_date'], 'notification_digest_member_uq');
            $table->index(['user_id', 'digest_date', 'delivered_at'], 'notification_digest_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_notification_digest_memberships');
    }
};
