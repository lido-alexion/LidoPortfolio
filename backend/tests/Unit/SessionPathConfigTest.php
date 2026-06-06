<?php

namespace Tests\Unit;

use Tests\TestCase;

class SessionPathConfigTest extends TestCase
{
    public function test_session_path_defaults_to_app_url_subdirectory(): void
    {
        config([
            'app.url' => 'https://lidoalexion.com/portfolio',
            'session.path' => '/portfolio',
        ]);

        $this->assertSame('/portfolio', config('session.path'));
    }

    public function test_session_path_is_root_for_local_app_url_without_path(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8001',
            'session.path' => '/',
        ]);

        $this->assertSame('/', config('session.path'));
    }
}
