<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserInviteTest extends TestCase
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

    public function test_admin_can_create_invite_and_list_it(): void
    {
        $this->makeAdmin();

        $email = 'invitee-'.Str::random(8).'@example.com';

        $create = $this->postJson('/api/invites', ['email' => $email]);
        $create->assertCreated()
            ->assertJsonPath('data.email', Str::lower($email))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['invite_url', 'invite_message', 'expires_at']]);

        $this->getJson('/api/invites')
            ->assertOk()
            ->assertJsonFragment(['email' => Str::lower($email)]);
    }

    public function test_non_admin_cannot_manage_invites(): void
    {
        $user = User::query()->create([
            'name' => 'User',
            'email' => 'user-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->postJson('/api/invites', ['email' => 'new@example.com'])->assertForbidden();
        $this->getJson('/api/invites')->assertForbidden();
    }

    public function test_guest_can_validate_and_accept_invite(): void
    {
        $this->makeAdmin();
        $email = 'accept-'.Str::random(8).'@example.com';

        $invite = $this->postJson('/api/invites', ['email' => $email])
            ->assertCreated()
            ->json('data');

        $token = Str::afterLast($invite['invite_url'], '/invite/');

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.email', Str::lower($email));

        $accept = $this->postJson('/api/invites/accept', [
            'token' => $token,
            'name' => 'Invited User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $accept->assertCreated()
            ->assertJsonPath('user.email', Str::lower($email));

        $this->assertDatabaseHas('portfolio_users', [
            'email' => Str::lower($email),
            'name' => 'Invited User',
        ]);

        $this->assertNotNull(
            UserInvite::query()->where('email', Str::lower($email))->value('accepted_at')
        );
    }

    public function test_login_with_pending_invite_redirect_payload(): void
    {
        $this->makeAdmin();
        $email = 'pending-'.Str::random(8).'@example.com';

        $invite = $this->postJson('/api/invites', ['email' => $email])
            ->assertCreated()
            ->json('data');

        $token = Str::afterLast($invite['invite_url'], '/invite/');

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'anything',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('invite_setup_required', true)
            ->assertJsonPath('invite_token', $token);
    }

    public function test_expired_invite_is_removed_on_access(): void
    {
        $admin = $this->makeAdmin();

        $invite = UserInvite::query()->create([
            'email' => 'expired@example.com',
            'token' => Str::random(64),
            'invited_by_user_id' => $admin->id,
            'expires_at' => now()->subHour(),
        ]);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/invites/{$invite->token}")
            ->assertStatus(410);

        $this->assertDatabaseMissing('portfolio_user_invites', ['id' => $invite->id]);
    }

    public function test_admin_can_regenerate_and_revoke_invite(): void
    {
        $this->makeAdmin();
        $email = 'manage-'.Str::random(8).'@example.com';

        $created = $this->postJson('/api/invites', ['email' => $email])
            ->assertCreated()
            ->json('data');

        $regenerated = $this->postJson("/api/invites/{$created['id']}/regenerate")
            ->assertOk()
            ->json('data');

        $this->assertNotSame($created['invite_url'], $regenerated['invite_url']);

        $this->deleteJson("/api/invites/{$created['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_user_invites', ['id' => $created['id']]);
    }
}

