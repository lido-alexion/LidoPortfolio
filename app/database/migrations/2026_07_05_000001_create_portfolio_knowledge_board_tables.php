<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_knowledge_notes')) {
            Schema::create('portfolio_knowledge_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->string('title');
                $table->longText('content_html')->nullable();
                $table->json('content_json')->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->boolean('is_favorite')->default(false);
                $table->boolean('is_archived')->default(false);
                $table->timestamps();

                $table->index(['profile_id', 'is_archived', 'updated_at'], 'pkn_profile_archived_updated_idx');
                $table->index(['profile_id', 'is_pinned'], 'pkn_profile_pinned_idx');
            });
        }

        if (! Schema::hasTable('portfolio_knowledge_tags')) {
            Schema::create('portfolio_knowledge_tags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->string('name', 64);
                $table->string('color', 7)->default('#6c757d');
                $table->timestamps();

                $table->unique(['profile_id', 'name'], 'pkt_profile_name_unique');
            });
        }

        if (! Schema::hasTable('portfolio_knowledge_note_tag')) {
            Schema::create('portfolio_knowledge_note_tag', function (Blueprint $table) {
                $table->foreignId('note_id')->constrained('portfolio_knowledge_notes')->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained('portfolio_knowledge_tags')->cascadeOnDelete();

                $table->primary(['note_id', 'tag_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_knowledge_note_tag');
        Schema::dropIfExists('portfolio_knowledge_tags');
        Schema::dropIfExists('portfolio_knowledge_notes');
    }
};
