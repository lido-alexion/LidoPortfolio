<?php

namespace Tests\Feature;

use App\Models\KnowledgeNote;
use App\Models\KnowledgeTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_crud_notes_and_tags(): void
    {
        $user = User::query()->create([
            'name' => 'KB User',
            'email' => 'kb-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user);

        $tag = $this->postJson('/api/knowledge-board/tags', [
            'name' => 'Dividend',
            'color' => '#198754',
        ]);
        $tag->assertCreated();
        $tagId = $tag->json('data.id');

        $create = $this->postJson('/api/knowledge-board/notes', [
            'title' => 'Dividend thesis',
            'content_html' => '<p>Focus on payout ratio.</p>',
            'content_json' => ['type' => 'doc', 'content' => []],
            'tag_ids' => [$tagId],
            'is_pinned' => true,
        ]);
        $create->assertCreated();
        $noteId = $create->json('data.id');

        $list = $this->getJson('/api/knowledge-board/notes?q=dividend');
        $list->assertOk();
        $list->assertJsonCount(1, 'data');

        $this->putJson("/api/knowledge-board/notes/{$noteId}", [
            'title' => 'Updated thesis',
        ])->assertOk()->assertJsonPath('data.title', 'Updated thesis');

        $dup = $this->postJson("/api/knowledge-board/notes/{$noteId}/duplicate");
        $dup->assertCreated();
        $this->assertDatabaseCount('portfolio_knowledge_notes', 2);

        $this->postJson('/api/knowledge-board/notes/bulk', [
            'action' => 'archive',
            'note_ids' => [$noteId],
        ])->assertOk();

        $this->assertTrue(
            KnowledgeNote::query()->findOrFail($noteId)->is_archived
        );

        $this->deleteJson("/api/knowledge-board/notes/{$noteId}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_knowledge_notes', ['id' => $noteId]);

        $target = $this->postJson('/api/knowledge-board/tags', ['name' => 'Income'])->json('data.id');
        $this->postJson('/api/knowledge-board/tags/merge', [
            'source_id' => $tagId,
            'target_id' => $target,
        ])->assertOk();

        $this->assertDatabaseMissing('portfolio_knowledge_tags', ['id' => $tagId]);
        $this->assertDatabaseHas('portfolio_knowledge_tags', ['id' => $target, 'name' => 'Income']);
    }

    public function test_duplicate_tag_name_is_rejected(): void
    {
        $user = User::query()->create([
            'name' => 'KB Dup',
            'email' => 'kb-dup-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $this->postJson('/api/knowledge-board/tags', ['name' => 'Moat'])->assertCreated();
        $this->postJson('/api/knowledge-board/tags', ['name' => 'moat'])->assertStatus(422);
    }

    public function test_list_notes_accepts_archived_query_boolean_strings(): void
    {
        $user = User::query()->create([
            'name' => 'KB Archived',
            'email' => 'kb-arch-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $this->getJson('/api/knowledge-board/notes?archived=false')->assertOk();
        $this->getJson('/api/knowledge-board/notes?archived=true')->assertOk();
    }

    public function test_note_title_is_derived_from_content_when_omitted(): void
    {
        $user = User::query()->create([
            'name' => 'KB Title',
            'email' => 'kb-title-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $this->postJson('/api/knowledge-board/notes', [
            'content_html' => '<p>Dividend compounding thesis</p>',
            'content_json' => ['type' => 'doc', 'content' => []],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Dividend compounding thesis');
    }
}
