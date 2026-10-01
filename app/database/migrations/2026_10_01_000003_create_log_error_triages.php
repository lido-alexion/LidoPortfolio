<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_log_error_triages', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->string('environment');
            $table->string('severity');
            $table->string('component')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('safe_message')->nullable();
            $table->json('safe_context')->nullable();
            $table->string('classification')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('evidence')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->unsignedBigInteger('github_issue_number')->nullable();
            $table->string('github_issue_url')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('triaged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_log_error_triages');
    }
};
