<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthCsrfLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_login_succeeds_with_plain_csrf_token_header(): void
    {
        $user = User::query()->create([
            'name' => 'Csrf User',
            'email' => 'csrf-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->get('/sanctum/csrf-cookie')->assertNoContent();

        $token = $this->getJson('/api/auth/csrf-token')
            ->assertOk()
            ->json('token');

        $this->assertNotEmpty($token);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ], [
            'X-CSRF-TOKEN' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->assertAuthenticatedAs($user);
    }
}
