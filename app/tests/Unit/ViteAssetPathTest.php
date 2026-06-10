<?php

namespace Tests\Unit;

use Illuminate\Foundation\Vite;
use Tests\TestCase;

class ViteAssetPathTest extends TestCase
{
    public function test_vite_entry_urls_are_root_relative_for_subdirectory_deploy(): void
    {
        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Production build manifest not present.');
        }

        config(['app.url' => 'https://lidoalexion.com/portfolio']);

        $url = app(Vite::class)->asset('resources/js/app.jsx');

        $this->assertStringStartsWith('/portfolio/build/', $url);
        $this->assertStringNotContainsString('://', $url);
    }
}
