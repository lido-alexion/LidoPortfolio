<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_knowledge_images')) {
            return;
        }

        Schema::create('portfolio_knowledge_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 64);
            $table->string('display_filename', 255);
            $table->string('full_filename', 255);
            $table->unsignedInteger('display_width')->nullable();
            $table->unsignedInteger('display_height')->nullable();
            $table->unsignedInteger('full_width')->nullable();
            $table->unsignedInteger('full_height')->nullable();
            $table->unsignedInteger('display_bytes')->default(0);
            $table->unsignedInteger('full_bytes')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'created_at'], 'pki_profile_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_knowledge_images');
    }
};
