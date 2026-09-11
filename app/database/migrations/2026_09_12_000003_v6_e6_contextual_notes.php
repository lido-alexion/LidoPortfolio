<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_contextual_notes')) {
            Schema::create('portfolio_contextual_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
                $table->foreignId('profile_id')->nullable()->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->string('context_key', 120);
                $table->string('subject_type', 80)->nullable();
                $table->string('subject_id', 120)->nullable();
                $table->text('body')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'profile_id', 'context_key', 'subject_type', 'subject_id'], 'contextual_notes_scope_uq');
                $table->index(['user_id', 'context_key', 'updated_at'], 'contextual_notes_context_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_contextual_notes');
    }
};
