<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    protected function makeUser(array $overrides = []): User
    {
        $isAdmin = (bool) ($overrides['is_admin'] ?? false);
        unset($overrides['is_admin']);

        $user = User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => 'user-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ], $overrides));

        if ($isAdmin) {
            $user->is_admin = true;
            $user->save();
        }

        return $user->fresh();
    }

    protected function actingAsUser(User $user): self
    {
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $this;
    }

    protected function createSession(User $user, string $id): void
    {
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0 Chrome/120.0 macOS',
            'payload' => json_encode(['logged_in_at' => now()->subHour()->timestamp]),
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_open_registration_is_disabled(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new-'.Str::random(8).'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $user = $this->makeUser();

        $this->actingAsUser($user)
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->makeUser(['is_admin' => true, 'email' => 'admin@example.com']);
        $member = $this->makeUser(['email' => 'member@example.com']);

        $this->actingAsUser($admin)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['email' => $admin->email, 'is_admin' => true])
            ->assertJsonFragment(['email' => $member->email, 'is_admin' => false]);
    }

    public function test_admin_can_change_other_user_role(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $member = $this->makeUser();

        $this->actingAsUser($admin)
            ->putJson("/api/users/{$member->id}/admin", ['is_admin' => true])
            ->assertOk()
            ->assertJsonPath('data.is_admin', true);

        $this->assertTrue($member->fresh()->is_admin);

        $this->putJson("/api/users/{$member->id}/admin", ['is_admin' => false])
            ->assertOk()
            ->assertJsonPath('data.is_admin', false);

        $this->assertFalse($member->fresh()->is_admin);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);

        $this->actingAsUser($admin)
            ->putJson("/api/users/{$admin->id}/admin", ['is_admin' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_admin']);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_me_includes_is_admin(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);

        $this->actingAsUser($admin)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.is_admin', true);
    }

    public function test_admin_can_list_and_revoke_one_target_user_session(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $member = $this->makeUser();
        $this->createSession($member, 'member-session-a');
        $this->createSession($member, 'member-session-b');

        $this->actingAsUser($admin)
            ->getJson("/api/users/{$member->id}/sessions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => 'member-session-a', 'is_current' => false]);

        $this->deleteJson("/api/users/{$member->id}/sessions/member-session-a")
            ->assertOk()
            ->assertJsonPath('sessions_removed', 1);

        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => 'member-session-a']);
        $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => 'member-session-b']);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);
    }

    public function test_admin_can_revoke_all_target_sessions_idempotently(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $member = $this->makeUser();
        $this->createSession($member, 'member-session-a');
        $this->createSession($member, 'member-session-b');

        $this->actingAsUser($admin)
            ->deleteJson("/api/users/{$member->id}/sessions")
            ->assertOk()
            ->assertJsonPath('sessions_removed', 2);

        $this->deleteJson("/api/users/{$member->id}/sessions")
            ->assertOk()
            ->assertJsonPath('sessions_removed', 0);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);
    }

    public function test_non_admin_cannot_manage_foreign_sessions(): void
    {
        $member = $this->makeUser();
        $other = $this->makeUser();
        $this->createSession($other, 'other-session');

        $this->actingAsUser($member)
            ->getJson("/api/users/{$other->id}/sessions")
            ->assertForbidden();
        $this->deleteJson("/api/users/{$other->id}/sessions/other-session")
            ->assertForbidden();
        $this->deleteJson("/api/users/{$other->id}/sessions")
            ->assertForbidden();

        $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => 'other-session']);
    }

    public function test_admin_cannot_use_cross_user_endpoint_for_own_session(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);

        $this->actingAsUser($admin)
            ->deleteJson("/api/users/{$admin->id}/sessions")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_revoke_missing_target_session_is_safe_and_scoped(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $member = $this->makeUser();
        $other = $this->makeUser();
        $this->createSession($other, 'unrelated-session');

        $this->actingAsUser($admin)
            ->deleteJson("/api/users/{$member->id}/sessions/missing-session")
            ->assertNotFound()
            ->assertJsonPath('message', 'The session is no longer active.');

        $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => 'unrelated-session']);
    }
}
