<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_transaction_request_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('idempotency_key_hash', 64);
            $table->string('payload_hash', 64);
            // Keep the committed identifier as a tombstone if the transaction is
            // later deleted; the idempotency key must never become reusable.
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'actor_user_id', 'idempotency_key_hash'], 'tx_req_receipt_scope_uq');
            $table->index(['profile_id', 'transaction_id'], 'tx_req_receipt_tx_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transaction_request_receipts');
    }
};
