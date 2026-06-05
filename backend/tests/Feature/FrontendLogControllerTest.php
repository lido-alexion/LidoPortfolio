<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FrontendLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_valid_frontend_log_payload(): void
    {
        $user = User::query()->create([
            'name' => 'Logger User',
            'email' => 'log-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/logs/frontend', [
            'level' => 'error',
            'message' => 'Failed to fetch holdings',
            'url' => '/dashboard',
            'userAgent' => 'PHPUnit',
            'timestamp' => now()->toIso8601String(),
            'requestId' => (string) Str::uuid(),
            'extra' => ['api' => '/api/holdings', 'category' => 'API'],
        ], [
            'X-Request-ID' => 'frontend-req-1',
        ]);

        $response->assertAccepted();
    }

    public function test_rejects_invalid_level_and_oversized_extra(): void
    {
        $user = User::query()->create([
            'name' => 'Logger User 2',
            'email' => 'log2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user);

        $this->postJson('/api/logs/frontend', [
            'level' => 'critical',
            'message' => 'bad level',
        ])->assertUnprocessable();

        $this->postJson('/api/logs/frontend', [
            'level' => 'error',
            'message' => 'ok',
            'extra' => ['blob' => str_repeat('x', 5000)],
        ])->assertUnprocessable();
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/logs/frontend', [
            'level' => 'error',
            'message' => 'unauthenticated',
        ])->assertUnauthorized();
    }
}
