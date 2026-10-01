<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_forward_collection_work', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key', 64);
            $table->string('exchange', 16)->default('');
            // Aggregate work uses a documented sentinel date; the unique
            // identity must never contain nullable components on MySQL.
            $table->date('session_date')->default('1970-01-01');
            $table->string('scope_key', 128);
            $table->string('state', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->string('last_error_code', 48)->nullable();
            $table->text('last_error')->nullable();
            $table->json('owner_evidence')->nullable();
            $table->timestamps();
            $table->unique(['dataset_key', 'exchange', 'session_date', 'scope_key'], 'stox_forward_work_identity_uq');
            $table->index(['state', 'next_attempt_at'], 'stox_forward_work_due_idx');
            $table->index(['dataset_key', 'session_date'], 'stox_forward_work_dataset_date_idx');
        });
        Schema::create('stox_forward_collection_control', function (Blueprint $table): void {
            $table->id();
            $table->boolean('paused')->default(false);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_forward_collection_work');
        Schema::dropIfExists('stox_forward_collection_control');
    }
};
