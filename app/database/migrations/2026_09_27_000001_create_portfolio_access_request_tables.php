<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_access_request_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('email_normalized', 255)->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portfolio_access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('email_normalized', 255)->index();
            $table->string('status', 32)->index();
            $table->timestamp('verified_at');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('admin_reason')->nullable();
            $table->unsignedBigInteger('user_invite_id')->nullable();
            $table->timestamp('resubmit_allowed_after')->nullable();
            $table->timestamps();

            $table->foreign('resolved_by_user_id')->references('id')->on('portfolio_users')->nullOnDelete();
            $table->foreign('user_invite_id')->references('id')->on('portfolio_user_invites')->nullOnDelete();
        });

        Schema::create('portfolio_access_request_bans', function (Blueprint $table) {
            $table->id();
            $table->string('email_normalized', 255)->index();
            $table->unsignedBigInteger('access_request_id')->nullable();
            $table->unsignedBigInteger('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at');
            $table->text('internal_reason')->nullable();
            $table->unsignedBigInteger('cleared_by_user_id')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->foreign('access_request_id')->references('id')->on('portfolio_access_requests')->nullOnDelete();
            $table->foreign('rejected_by_user_id')->references('id')->on('portfolio_users')->nullOnDelete();
            $table->foreign('cleared_by_user_id')->references('id')->on('portfolio_users')->nullOnDelete();
        });

        Schema::create('portfolio_access_request_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64)->index();
            $table->string('email_normalized', 255)->nullable()->index();
            $table->unsignedBigInteger('access_request_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at');

            $table->foreign('access_request_id')->references('id')->on('portfolio_access_requests')->nullOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('portfolio_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_access_request_audit_events');
        Schema::dropIfExists('portfolio_access_request_bans');
        Schema::dropIfExists('portfolio_access_requests');
        Schema::dropIfExists('portfolio_access_request_verifications');
    }
};
