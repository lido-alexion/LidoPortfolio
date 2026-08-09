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

        $otherId = $this->insertOtherSession($user->id);

        $sessions = $this->getJson('/api/auth/sessions')
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($sessions)->contains(fn ($row) => $row['id'] === $otherId));
        $this->assertTrue(collect($sessions)->contains(fn ($row) => array_key_exists('is_current', $row)));
        $this->assertTrue(collect($sessions)->contains(fn ($row) => array_key_exists('device', $row)));

        $this->postJson('/api/auth/sessions/logout-others')
            ->assertOk();

        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $otherId]);
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_list_for_user_marks_matching_id_as_current(): void
    {
        $user = User::query()->create([
            'name' => 'List Mark',
            'email' => 'list-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $currentId = 'current-session-id-aaaaaaaaaaaaaaaa';
        $otherId = $this->insertOtherSession($user->id, 'other-session-id-bbbbbbbbbbbbbbbb');
        $this->insertOtherSession($user->id, $currentId);

        $list = app(\App\Services\SessionManagementService::class)
            ->listForUser($user->id, $currentId);

        $marked = collect($list)->firstWhere('id', $currentId);
        $other = collect($list)->firstWhere('id', $otherId);
        $this->assertTrue($marked['is_current']);
        $this->assertFalse($other['is_current']);
    }

    public function test_revoke_other_session_and_reject_foreign_session(): void
    {
        $user = User::query()->create([
            'name' => 'Revoke User',
            'email' => 'revoke-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $other = User::query()->create([
            'name' => 'Other Owner',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $ownedOther = $this->insertOtherSession($user->id);
        $foreign = $this->insertOtherSession($other->id);

        $this->deleteJson('/api/auth/sessions/'.$ownedOther)->assertOk();
        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $ownedOther]);

        $this->deleteJson('/api/auth/sessions/'.$foreign)->assertUnprocessable();
        $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => $foreign]);
    }

    public function test_destroy_session_refuses_to_delete_current_id(): void
    {
        $service = app(\App\Services\SessionManagementService::class);
        $this->assertFalse($service->destroySession(1, 'same-session-id', 'same-session-id'));
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

    protected function insertOtherSession(int $userId, ?string $sessionId = null): string
    {
        $id = $sessionId ?? Str::random(40);
        \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.51',
            'user_agent' => 'OtherDevice/1.0',
            'payload' => '',
            'last_activity' => time(),
        ]);

        return $id;
    }
}
