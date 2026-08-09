<?php

namespace Tests\Feature;

use App\Models\PasswordResetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetLinkTest extends TestCase
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

    protected function makeAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->is_admin = true;
        $user->save();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $user->fresh();
    }

    protected function makeMember(): User
    {
        return User::query()->create([
            'name' => 'Member',
            'email' => 'member-'.Str::random(8).'@example.com',
            'password' => Hash::make('old-password'),
        ]);
    }

    public function test_admin_can_create_reset_link_and_list_it(): void
    {
        $this->makeAdmin();
        $member = $this->makeMember();

        $create = $this->postJson('/api/password-reset-links', ['user_id' => $member->id]);
        $create->assertCreated()
            ->assertJsonPath('data.email', $member->email)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['reset_url', 'reset_message', 'expires_at']]);

        $this->getJson('/api/password-reset-links')
            ->assertOk()
            ->assertJsonFragment(['email' => $member->email]);
    }

    public function test_non_admin_cannot_manage_reset_links(): void
    {
        $member = $this->makeMember();

        $this->postJson('/api/auth/login', [
            'email' => $member->email,
            'password' => 'old-password',
        ])->assertOk();

        $this->postJson('/api/password-reset-links', ['user_id' => $member->id])->assertForbidden();
        $this->getJson('/api/password-reset-links')->assertForbidden();
    }

    public function test_guest_can_validate_and_accept_reset_link(): void
    {
        $this->makeAdmin();
        $member = $this->makeMember();

        $link = $this->postJson('/api/password-reset-links', ['user_id' => $member->id])
            ->assertCreated()
            ->json('data');

        $token = Str::afterLast($link['reset_url'], '/reset-password/');

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/reset-password/{$token}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.email', $member->email);

        $this->postJson('/api/reset-password/accept', [
            'token' => $token,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk()
            ->assertJsonPath('user.email', $member->email);

        $member->refresh();
        $this->assertTrue(Hash::check('new-password-1', $member->password));

        $this->assertNotNull(
            PasswordResetLink::query()->where('user_id', $member->id)->value('used_at')
        );
    }

    public function test_expired_reset_link_is_removed_on_access(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();

        $link = PasswordResetLink::query()->create([
            'user_id' => $member->id,
            'token' => Str::random(64),
            'created_by_user_id' => $admin->id,
            'expires_at' => now()->subHour(),
        ]);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/reset-password/{$link->token}")
            ->assertStatus(410);

        $this->assertDatabaseMissing('portfolio_password_reset_links', ['id' => $link->id]);
    }

    public function test_admin_can_regenerate_and_revoke_reset_link(): void
    {
        $this->makeAdmin();
        $member = $this->makeMember();

        $created = $this->postJson('/api/password-reset-links', ['user_id' => $member->id])
            ->assertCreated()
            ->json('data');

        $regenerated = $this->postJson("/api/password-reset-links/{$created['id']}/regenerate")
            ->assertOk()
            ->json('data');

        $this->assertNotSame($created['reset_url'], $regenerated['reset_url']);

        $this->deleteJson("/api/password-reset-links/{$created['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_password_reset_links', ['id' => $created['id']]);
    }

    public function test_cannot_create_duplicate_pending_reset_link_for_user(): void
    {
        $this->makeAdmin();
        $member = $this->makeMember();

        $this->postJson('/api/password-reset-links', ['user_id' => $member->id])
            ->assertCreated();

        $this->postJson('/api/password-reset-links', ['user_id' => $member->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_password_reset_accept_revokes_other_sessions_and_rotates_remember_token(): void
    {
        $this->makeAdmin();
        $member = $this->makeMember();

        $oldRemember = 'reset-remember-before-aaaaaaaa';
        $member->setRememberToken($oldRemember);
        $member->save();

        $preExistingSession = $this->insertOtherSession($member->id);

        $link = $this->postJson('/api/password-reset-links', ['user_id' => $member->id])
            ->assertCreated()
            ->json('data');
        $token = Str::afterLast($link['reset_url'], '/reset-password/');

        $this->postJson('/api/auth/logout')->assertOk();

        $accept = $this->postJson('/api/reset-password/accept', [
            'token' => $token,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertAuthenticatedAs($member);
        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => $preExistingSession]);
        $this->assertGreaterThanOrEqual(1, (int) $accept->json('sessions_removed'));

        $member->refresh();
        $this->assertTrue(Hash::check('new-password-1', $member->password));
        $this->assertFalse(Hash::check('old-password', $member->password));
        $this->assertNotSame($oldRemember, $member->remember_token);
        $this->assertNotEmpty($member->remember_token);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $member->email);
    }

    public function test_invalid_password_reset_accept_does_not_revoke_sessions(): void
    {
        $member = $this->makeMember();
        $preExistingSession = $this->insertOtherSession($member->id);
        $rememberBefore = 'reset-remember-unchanged-bbbbbbbb';
        $member->setRememberToken($rememberBefore);
        $member->save();

        $this->postJson('/api/reset-password/accept', [
            'token' => Str::random(64),
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertUnprocessable();

        $this->assertDatabaseHas(config('session.table', 'sessions'), ['id' => $preExistingSession]);
        $member->refresh();
        $this->assertSame($rememberBefore, $member->remember_token);
        $this->assertTrue(Hash::check('old-password', $member->password));
    }

    protected function insertOtherSession(int $userId, ?string $sessionId = null): string
    {
        $id = $sessionId ?? Str::random(40);
        \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.52',
            'user_agent' => 'OtherDevice/1.0',
            'payload' => '',
            'last_activity' => time(),
        ]);

        return $id;
    }
}
