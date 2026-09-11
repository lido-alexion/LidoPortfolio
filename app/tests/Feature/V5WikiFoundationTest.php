<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
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

    public function test_api_creates_updates_and_moves_pages_with_revision_history_and_portfolio_isolation(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $other = PortfolioProfile::query()->create(['user_id' => $user->id, 'name' => 'Other', 'portfolio_type' => 'live']);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        $rootUuid = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Root', 'markdown' => '# Root'])
            ->assertCreated()->json('data.uuid');
        $childUuid = $this->postJson('/api/knowledge-board/wiki/pages', [
            'title' => 'Child', 'markdown' => 'See the root.', 'parent_uuid' => $rootUuid,
        ])->assertCreated()->json('data.uuid');

        $this->putJson('/api/knowledge-board/wiki/pages/'.$childUuid, ['title' => 'Renamed child'])
            ->assertOk()->assertJsonPath('data.slug', 'renamed-child');
        $this->putJson('/api/knowledge-board/wiki/pages/'.$childUuid.'/move', ['parent_uuid' => null, 'display_order' => 3])
            ->assertOk()->assertJsonPath('data.display_order', 3);
        $this->getJson('/api/knowledge-board/wiki/pages/'.$childUuid)->assertOk()
            ->assertJsonCount(3, 'data.revisions');

        $this->withProfileHeader($user, $other)
            ->getJson('/api/knowledge-board/wiki/pages/'.$childUuid)->assertNotFound();
    }

    public function test_cycle_and_unconfirmed_branch_deletion_are_rejected(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $root = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Root'])->json('data.uuid');
        $child = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Child', 'parent_uuid' => $root])->json('data.uuid');

        $this->putJson('/api/knowledge-board/wiki/pages/'.$root.'/move', ['parent_uuid' => $child, 'display_order' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->deleteJson('/api/knowledge-board/wiki/pages/'.$root)
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_count');
        $this->deleteJson('/api/knowledge-board/wiki/pages/'.$root, ['recursive' => true, 'confirm_count' => 2])
            ->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertDatabaseCount('portfolio_wiki_pages', 0);
    }

    public function test_markdown_is_sanitized_and_stable_links_follow_target_rename(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $target = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Original'])->json('data.uuid');
        $source = $this->postJson('/api/knowledge-board/wiki/pages', [
            'title' => 'Source',
            'markdown' => "[[wiki:{$target}]]\n\n<script>alert(1)</script>\n\n[unsafe](javascript:alert(1))",
        ])->json('data.uuid');

        $this->putJson('/api/knowledge-board/wiki/pages/'.$target, ['title' => 'Renamed'])->assertOk();
        $response = $this->getJson('/api/knowledge-board/wiki/pages/'.$source)->assertOk()
            ->assertJsonPath('data.breadcrumbs.0.title', 'Knowledge Board');
        $html = $response->json('data.rendered_html');

        $this->assertStringContainsString('Renamed', $html);
        $this->assertStringContainsString('/knowledge-board/wiki/'.$target, $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_missing_and_cross_portfolio_wiki_links_do_not_leak_titles(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $other = PortfolioProfile::query()->create(['user_id' => $user->id, 'name' => 'Other', 'portfolio_type' => 'live']);
        $private = WikiPage::query()->create([
            'profile_id' => $other->id, 'uuid' => (string) Str::uuid(), 'title' => 'Secret title', 'slug' => 'secret-title', 'markdown' => 'Secret',
        ]);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $source = $this->postJson('/api/knowledge-board/wiki/pages', [
            'title' => 'Source', 'markdown' => '[[wiki:'.$private->uuid.']]',
        ])->json('data.uuid');

        $html = $this->getJson('/api/knowledge-board/wiki/pages/'.$source)->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('Missing Wiki Page', $html);
        $this->assertStringNotContainsString('Secret title', $html);
    }

    public function test_revision_can_be_compared_and_restored_without_erasing_later_history(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $uuid = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'First', 'markdown' => 'Version one'])
            ->assertCreated()->json('data.uuid');
        $revisionId = WikiPage::query()->where('uuid', $uuid)->firstOrFail()->revisions()->sole()->id;
        $this->putJson('/api/knowledge-board/wiki/pages/'.$uuid, ['title' => 'Second', 'markdown' => 'Version two'])->assertOk();

        $this->getJson('/api/knowledge-board/wiki/pages/'.$uuid.'/revisions/'.$revisionId)->assertOk()
            ->assertJsonPath('data.revision.markdown', 'Version one')
            ->assertJsonPath('data.current.markdown', 'Version two')
            ->assertJsonPath('data.changed.markdown', true);
        $this->postJson('/api/knowledge-board/wiki/pages/'.$uuid.'/revisions/'.$revisionId.'/restore')->assertOk()
            ->assertJsonPath('data.title', 'First')
            ->assertJsonPath('data.markdown', 'Version one');

        $page = WikiPage::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(3, $page->revisions()->count());
        $this->assertSame('restored', $page->revisions()->first()->change_type);
    }
}
