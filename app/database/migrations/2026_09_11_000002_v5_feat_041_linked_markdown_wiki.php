<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('portfolio_wiki_pages')->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug');
            $table->longText('markdown')->default('');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['profile_id', 'parent_id', 'display_order'], 'wiki_page_tree');
            $table->index(['profile_id', 'slug']);
        });

        Schema::create('portfolio_wiki_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('portfolio_wiki_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('change_type');
            $table->string('title');
            $table->string('slug');
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->longText('markdown')->default('');
            $table->timestamp('created_at');
            $table->unique(['page_id', 'revision_number']);
        });

        Schema::create('portfolio_wiki_page_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('portfolio_wiki_pages')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
            $table->index(['page_id', 'revoked_at']);
        });

        Schema::create('portfolio_wiki_page_images', function (Blueprint $table) {
            $table->foreignId('page_id')->constrained('portfolio_wiki_pages')->cascadeOnDelete();
            $table->foreignId('image_id')->constrained('portfolio_knowledge_images')->cascadeOnDelete();
            $table->primary(['page_id', 'image_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_wiki_page_images');
        Schema::dropIfExists('portfolio_wiki_page_shares');
        Schema::dropIfExists('portfolio_wiki_page_revisions');
        Schema::dropIfExists('portfolio_wiki_pages');
    }
};
