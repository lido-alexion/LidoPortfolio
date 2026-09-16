<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BuildInfoControllerTest extends TestCase
{
    private string $buildInfoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfoPath = base_path('bootstrap/build-info.json');
        File::delete($this->buildInfoPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->buildInfoPath);

        parent::tearDown();
    }

    public function test_returns_local_metadata_when_build_file_is_missing(): void
    {
        $this->getJson('/api/build-info')
            ->assertOk()
            ->assertJsonPath('data.build_id', 'local')
            ->assertJsonPath('data.commit_sha', null);
    }

    public function test_returns_build_metadata_from_release_file(): void
    {
        File::put($this->buildInfoPath, json_encode([
            'build_id' => 'build-123-attempt-1-abcdef123456',
            'commit_sha' => 'abcdef1234567890',
            'short_sha' => 'abcdef123456',
            'ref' => 'master',
            'workflow' => 'Deploy StoXla Production',
            'run_id' => '987654321',
            'run_number' => '123',
            'run_attempt' => '1',
            'built_at' => '2026-09-16T12:00:00.000Z',
        ], JSON_PRETTY_PRINT));

        $this->getJson('/api/build-info')
            ->assertOk()
            ->assertJsonPath('data.build_id', 'build-123-attempt-1-abcdef123456')
            ->assertJsonPath('data.short_sha', 'abcdef123456')
            ->assertJsonPath('data.built_at', '2026-09-16T12:00:00.000Z');
    }
}
