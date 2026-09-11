<?php

namespace Tests\Feature;

use App\Models\KnowledgeImage;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Services\Wiki\WikiExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
            ->assertOk()->assertJsonPath('data.display_order', 1);
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

    public function test_public_share_is_opaque_minimal_revocable_and_regeneration_never_revives_old_url(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $uuid = $this->postJson('/api/knowledge-board/wiki/pages', [
            'title' => 'Shared research', 'markdown' => '# Public\n\n<script>bad()</script>',
        ])->json('data.uuid');
        $oldUrl = $this->postJson('/api/knowledge-board/wiki/pages/'.$uuid.'/share')->assertCreated()->json('data.url');
        $oldToken = basename(parse_url($oldUrl, PHP_URL_PATH));

        $this->assertDatabaseMissing('portfolio_wiki_page_shares', ['token_hash' => $oldToken]);
        $this->getJson('/api/wiki/shared/'.$oldToken)->assertOk()
            ->assertJsonPath('data.title', 'Shared research')
            ->assertJsonMissingPath('data.uuid')
            ->assertJsonMissingPath('data.markdown');
        $this->assertStringNotContainsString('<script', $this->getJson('/api/wiki/shared/'.$oldToken)->json('data.rendered_html'));

        $newUrl = $this->postJson('/api/knowledge-board/wiki/pages/'.$uuid.'/share/regenerate')->assertOk()->json('data.url');
        $newToken = basename(parse_url($newUrl, PHP_URL_PATH));
        $this->assertNotSame($oldToken, $newToken);
        $this->getJson('/api/wiki/shared/'.$oldToken)->assertNotFound();
        $this->getJson('/api/wiki/shared/'.$newToken)->assertOk();
        $this->deleteJson('/api/knowledge-board/wiki/pages/'.$uuid.'/share')->assertOk();
        $this->getJson('/api/wiki/shared/'.$newToken)->assertNotFound();
    }

    public function test_public_internal_links_only_reveal_independently_shared_targets(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $target = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Private target'])->json('data.uuid');
        $source = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Source', 'markdown' => '[[wiki:'.$target.']]'])->json('data.uuid');
        $sourceToken = basename(parse_url($this->postJson('/api/knowledge-board/wiki/pages/'.$source.'/share')->json('data.url'), PHP_URL_PATH));

        $privateHtml = $this->getJson('/api/wiki/shared/'.$sourceToken)->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('Private Wiki Page', $privateHtml);
        $this->assertStringNotContainsString('Private target', $privateHtml);

        $targetUrl = $this->postJson('/api/knowledge-board/wiki/pages/'.$target.'/share')->json('data.url');
        $publicHtml = $this->getJson('/api/wiki/shared/'.$sourceToken)->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('Private target', $publicHtml);
        $this->assertStringContainsString($targetUrl, $publicHtml);
    }

    public function test_knowledge_search_combines_notes_and_wiki_with_hierarchy_context(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/knowledge-board/notes', ['title' => 'Valuation note', 'content_html' => '<p>Quality thesis</p>'])->assertCreated();
        $root = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Companies'])->json('data.uuid');
        $this->postJson('/api/knowledge-board/wiki/pages', [
            'title' => 'Valuation note', 'markdown' => 'Quality thesis', 'parent_uuid' => $root,
        ])->assertCreated();

        $response = $this->getJson('/api/knowledge-board/search?q=Quality')->assertOk();
        $this->assertSame(['note', 'wiki_page'], $response->json('data.*.type'));
        $this->assertSame('Knowledge Board / Notes', $response->json('data.0.context'));
        $this->assertSame('Knowledge Board / Companies / Valuation note', $response->json('data.1.context'));
    }

    public function test_public_managed_images_are_limited_to_images_attached_to_that_shared_page(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $attached = $this->wikiImage($profile, 'attached.jpg');
        $unrelated = $this->wikiImage($profile, 'unrelated.jpg');
        $page = $this->postJson('/api/knowledge-board/wiki/pages', [
            'title' => 'Illustrated', 'markdown' => '![Chart](wiki-image:'.$attached->uuid.')',
        ])->json('data.uuid');
        $this->postJson('/api/knowledge-board/wiki/pages/'.$page.'/images/'.$attached->uuid)->assertOk();
        $shareUrl = $this->postJson('/api/knowledge-board/wiki/pages/'.$page.'/share')->json('data.url');
        $token = basename(parse_url($shareUrl, PHP_URL_PATH));

        $html = $this->getJson('/api/wiki/shared/'.$token)->assertOk()->json('data.rendered_html');
        $this->assertStringContainsString('/api/wiki/shared/'.$token.'/images/'.$attached->uuid, $html);
        $this->get('/api/wiki/shared/'.$token.'/images/'.$attached->uuid)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson('/api/wiki/shared/'.$token.'/images/'.$unrelated->uuid)->assertNotFound();

        File::deleteDirectory(storage_path('app/knowledge-images/'.$profile->id));
    }

    private function wikiImage(PortfolioProfile $profile, string $filename): KnowledgeImage
    {
        $uuid = (string) Str::uuid();
        $directory = storage_path('app/knowledge-images/'.$profile->id);
        File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/'.$filename, 'test-image');

        return KnowledgeImage::query()->create([
            'profile_id' => $profile->id, 'uuid' => $uuid, 'mime_type' => 'image/jpeg',
            'display_filename' => $filename, 'full_filename' => $filename,
        ]);
    }

    public function test_page_and_branch_exports_rewrite_included_links_and_mark_excluded_targets(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $root = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Root'])->json('data.uuid');
        $child = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Child', 'parent_uuid' => $root])->json('data.uuid');
        $outside = $this->postJson('/api/knowledge-board/wiki/pages', ['title' => 'Outside'])->json('data.uuid');
        $this->putJson('/api/knowledge-board/wiki/pages/'.$root, [
            'markdown' => "Included [[wiki:{$child}]]\nExcluded [[wiki:{$outside}]]",
        ])->assertOk();

        $single = $this->get('/api/knowledge-board/wiki/pages/'.$root.'/export')->assertOk();
        $this->assertStringContainsString('Unavailable Wiki Page', $single->getContent());

        $response = app(WikiExportService::class)->archive($profile, $root);
        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(2, $zip->numFiles);
        $rootEntry = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->first(fn (string $name) => ! str_contains($name, '/'));
        $markdown = $zip->getFromName($rootEntry);
        $this->assertStringContainsString('child-', $markdown);
        $this->assertStringContainsString('Unavailable Wiki Page', $markdown);
        $zip->close();
        @unlink($path);
    }

    public function test_parent_deletion_does_not_rewrite_immutable_hierarchy_evidence(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $parent = WikiPage::query()->create(['profile_id' => $profile->id, 'uuid' => (string) Str::uuid(), 'title' => 'Parent', 'slug' => 'parent', 'markdown' => '']);
        $child = WikiPage::query()->create(['profile_id' => $profile->id, 'parent_id' => $parent->id, 'uuid' => (string) Str::uuid(), 'title' => 'Child', 'slug' => 'child', 'markdown' => '']);
        $revision = WikiPageRevision::query()->create([
            'page_id' => $child->id, 'user_id' => $user->id, 'revision_number' => 1, 'change_type' => 'created',
            'title' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id, 'display_order' => 0, 'markdown' => '', 'created_at' => now(),
        ]);
        $child->update(['parent_id' => null]);
        $parent->delete();

        $this->assertSame($parent->id, $revision->fresh()->parent_id);
    }
}
