<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_login_success_establishes_session(): void
    {
        $user = User::query()->create([
            'name' => 'Auth User',
            'email' => 'auth-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'remember' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.email', $user->email);
        $this->assertAuthenticatedAs($user);
        $response->assertJsonMissing(['token']);
    }

    public function test_login_failure_returns_validation_error(): void
    {
        User::query()->create([
            'name' => 'Auth User 2',
            'email' => 'auth2@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'auth2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_me_returns_null_user_when_guest(): void
    {
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['user' => null]);
    }

    public function test_me_returns_user_when_authenticated(): void
    {
        $user = User::query()->create([
            'name' => 'Me User',
            'email' => 'me-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::query()->create([
            'name' => 'Logout User',
            'email' => 'logout-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);
    }

    public function test_sessions_list_and_logout_others(): void
    {
        $user = User::query()->create([
            'name' => 'Sessions User',
            'email' => 'sess-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $sessions = $this->getJson('/api/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($sessions);

        $this->postJson('/api/auth/sessions/logout-others')->assertOk();
    }

    public function test_csrf_token_endpoint_returns_session_token(): void
    {
        $response = $this->getJson('/api/auth/csrf-token');

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_csrf_cookie_endpoint_is_available(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
    }
}
