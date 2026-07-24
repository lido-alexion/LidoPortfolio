<?php

namespace Tests\Feature;

use App\Models\KnowledgeImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeBoardImageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal 1×1 JPEG (no GD required).
     */
    private function jpegUpload(string $name): UploadedFile
    {
        $binary = hex2bin(
            'ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffdb0043010909090c0b0c180d0d1832211c213232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232323232ffc00011080001000103011100021100031101ffc40014000100000000000000000000000000000000ffc40014100100000000000000000000000000000000ffda000c0301000210031000003f00bf80ffd9'
        );

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kb_'.Str::random(8).'_'.$name;
        file_put_contents($path, $binary);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    public function test_user_can_upload_and_fetch_display_and_full_images(): void
    {
        $user = User::query()->create([
            'name' => 'Image User',
            'email' => 'kb-img-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user);

        $upload = $this->post('/api/knowledge-board/images', [
            'display' => $this->jpegUpload('chart_display.jpg'),
            'full' => $this->jpegUpload('chart_full.jpg'),
            'original_name' => 'chart.jpg',
            'display_width' => 800,
            'display_height' => 600,
            'full_width' => 2000,
            'full_height' => 1500,
        ], ['Accept' => 'application/json']);

        $upload->assertCreated();
        $upload->assertJsonPath('data.original_name', 'chart.jpg');
        $upload->assertJsonPath('data.display_width', 800);
        $upload->assertJsonPath('data.full_width', 2000);

        $uuid = $upload->json('data.uuid');
        $this->assertNotEmpty($uuid);
        $this->assertStringContainsString('/api/knowledge-board/images/'.$uuid, (string) $upload->json('data.display_url'));
        $this->assertStringContainsString('/full', (string) $upload->json('data.full_url'));

        $this->assertDatabaseHas('portfolio_knowledge_images', [
            'profile_id' => $profile->id,
            'uuid' => $uuid,
        ]);

        $this->get('/api/knowledge-board/images/'.$uuid)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->get('/api/knowledge-board/images/'.$uuid.'/full')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $image = KnowledgeImage::query()->where('uuid', $uuid)->firstOrFail();
        $dir = storage_path('app/knowledge-images/'.$profile->id);
        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.$image->display_filename);
        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.$image->full_filename);

        File::deleteDirectory($dir);
    }

    public function test_other_portfolio_cannot_fetch_image(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'kb-own-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $ownerProfile = $this->defaultPortfolioFor($owner);

        $this->actingAs($owner);
        $upload = $this->post('/api/knowledge-board/images', [
            'display' => $this->jpegUpload('a.jpg'),
            'full' => $this->jpegUpload('b.jpg'),
        ], ['Accept' => 'application/json']);
        $upload->assertCreated();
        $uuid = $upload->json('data.uuid');

        $intruder = User::query()->create([
            'name' => 'Intruder',
            'email' => 'kb-int-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($intruder);
        $this->actingAs($intruder);

        $this->getJson('/api/knowledge-board/images/'.$uuid)->assertNotFound();

        $dir = storage_path('app/knowledge-images/'.$ownerProfile->id);
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    public function test_rejects_non_image_upload(): void
    {
        $user = User::query()->create([
            'name' => 'Bad Upload',
            'email' => 'kb-bad-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kb_'.Str::random(8).'.txt';
        file_put_contents($path, 'not an image');
        $file = new UploadedFile($path, 'notes.txt', 'text/plain', null, true);

        $this->post('/api/knowledge-board/images', [
            'display' => $file,
            'full' => $file,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
