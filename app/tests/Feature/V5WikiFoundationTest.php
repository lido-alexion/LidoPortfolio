<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class V5WikiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_identity_is_independent_of_title_slug_and_hierarchy(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $root = WikiPage::query()->create([
            'profile_id' => $profile->id,
            'uuid' => (string) Str::uuid(),
            'title' => 'Research',
            'slug' => 'research',
            'markdown' => '# Research',
        ]);
        $child = WikiPage::query()->create([
            'profile_id' => $profile->id,
            'parent_id' => $root->id,
            'uuid' => (string) Str::uuid(),
            'title' => 'Research',
            'slug' => 'research',
            'markdown' => 'Duplicate titles are valid.',
        ]);

        $identity = $child->uuid;
        $child->update(['title' => 'Renamed', 'slug' => 'renamed', 'parent_id' => null]);

        $this->assertSame($identity, $child->fresh()->uuid);
        $this->assertCount(2, $profile->wikiPages);
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_revision_records_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::query()->create([
            'profile_id' => $this->defaultPortfolioFor($user)->id,
            'uuid' => (string) Str::uuid(),
            'title' => 'Page',
            'slug' => 'page',
            'markdown' => 'One',
        ]);
        $revision = WikiPageRevision::query()->create([
            'page_id' => $page->id,
            'user_id' => $user->id,
            'revision_number' => 1,
            'change_type' => 'created',
            'title' => $page->title,
            'slug' => $page->slug,
            'markdown' => $page->markdown,
            'created_at' => now(),
        ]);

        try {
            $revision->update(['markdown' => 'Changed']);
            $this->fail('Revision update unexpectedly succeeded.');
        } catch (LogicException) {
            $this->assertSame('One', $revision->fresh()->markdown);
        }

        $this->expectException(LogicException::class);
        $revision->delete();
    }
}
