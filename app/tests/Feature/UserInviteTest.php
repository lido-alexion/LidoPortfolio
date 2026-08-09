<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvite;
use App\Services\UserInviteService;
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

    protected function rawTokenFromCreatePayload(array $data): string
    {
        return Str::afterLast($data['invite_url'], '/invite/');
    }

    public function test_admin_can_create_invite_stores_hash_not_raw_token(): void
    {
        $this->makeAdmin();
        $email = 'invitee-'.Str::random(8).'@example.com';

        $create = $this->postJson('/api/invites', ['email' => $email]);
        $create->assertCreated()
            ->assertJsonPath('data.email', Str::lower($email))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.url_available', true)
            ->assertJsonStructure(['data' => ['invite_url', 'invite_message', 'expires_at']]);

        $raw = $this->rawTokenFromCreatePayload($create->json('data'));
        $this->assertSame(64, strlen($raw));

        $invite = UserInvite::query()->where('email', Str::lower($email))->first();
        $this->assertNotNull($invite);
        $this->assertNotSame($raw, $invite->token);
        $this->assertSame(hash('sha256', $raw), $invite->token);
        $this->assertArrayNotHasKey('token', $invite->toArray());

        $list = $this->getJson('/api/invites')->assertOk()->json('data');
        $listed = collect($list)->firstWhere('email', Str::lower($email));
        $this->assertNotNull($listed);
        $this->assertNull($listed['invite_url']);
        $this->assertFalse($listed['url_available']);
    }

    public function test_stored_hash_cannot_be_used_as_invitation_token(): void
    {
        $this->makeAdmin();
        $email = 'hash-reject-'.Str::random(8).'@example.com';
        $created = $this->postJson('/api/invites', ['email' => $email])->assertCreated()->json('data');
        $invite = UserInvite::query()->find($created['id']);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson('/api/invites/'.$invite->token)->assertStatus(410);
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

        $token = $this->rawTokenFromCreatePayload($invite);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.email', Str::lower($email))
            ->assertJsonMissing(['token']);

        $accept = $this->postJson('/api/invites/accept', [
            'token' => $token,
            'name' => 'Invited User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $accept->assertCreated()
            ->assertJsonPath('user.email', Str::lower($email))
            ->assertJsonMissing(['sessions_removed']);

        $this->assertStringNotContainsString('"token"', $accept->getContent());

        $this->assertDatabaseHas('portfolio_users', [
            'email' => Str::lower($email),
            'name' => 'Invited User',
        ]);

        $this->assertNotNull(
            UserInvite::query()->where('email', Str::lower($email))->value('accepted_at')
        );
        $this->assertAuthenticated();
    }

    public function test_invalid_and_consumed_tokens_fail(): void
    {
        $this->makeAdmin();
        $email = 'consume-'.Str::random(8).'@example.com';
        $created = $this->postJson('/api/invites', ['email' => $email])->assertCreated()->json('data');
        $token = $this->rawTokenFromCreatePayload($created);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson('/api/invites/'.Str::random(64))->assertStatus(410);

        $this->postJson('/api/invites/accept', [
            'token' => $token,
            'name' => 'First',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        // Consumed token must fail even while the first accept session is active.
        $this->postJson('/api/invites/accept', [
            'token' => $token,
            'name' => 'Second',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_expired_invite_is_removed_on_access(): void
    {
        $admin = $this->makeAdmin();
        $raw = Str::random(64);

        $invite = UserInvite::query()->create([
            'email' => 'expired@example.com',
            'token' => hash('sha256', $raw),
            'invited_by_user_id' => $admin->id,
            'expires_at' => now()->subHour(),
        ]);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/invites/{$raw}")
            ->assertStatus(410);

        $this->assertDatabaseMissing('portfolio_user_invites', ['id' => $invite->id]);
    }

    public function test_regenerate_rotates_token_preserves_expiry_and_invalidates_old(): void
    {
        $this->makeAdmin();
        $email = 'manage-'.Str::random(8).'@example.com';

        $created = $this->postJson('/api/invites', ['email' => $email])
            ->assertCreated()
            ->json('data');

        $oldRaw = $this->rawTokenFromCreatePayload($created);
        $originalExpires = $created['expires_at'];
        $inviteId = $created['id'];

        $before = UserInvite::query()->find($inviteId);
        $this->assertNotNull($before);
        // Freeze clock skew: ensure expires_at unchanged after regenerate
        $expiresBefore = $before->expires_at->copy();

        $regenerated = $this->postJson("/api/invites/{$inviteId}/regenerate")
            ->assertOk()
            ->json('data');

        $newRaw = $this->rawTokenFromCreatePayload($regenerated);
        $this->assertNotSame($oldRaw, $newRaw);
        $this->assertNotSame($created['invite_url'], $regenerated['invite_url']);
        $this->assertSame($originalExpires, $regenerated['expires_at']);

        $after = UserInvite::query()->find($inviteId);
        $this->assertNotNull($after);
        $this->assertTrue($expiresBefore->equalTo($after->expires_at));
        $this->assertSame(hash('sha256', $newRaw), $after->token);
        $this->assertSame(1, UserInvite::query()->where('email', Str::lower($email))->whereNull('accepted_at')->count());

        $this->postJson('/api/auth/logout')->assertOk();

        $this->getJson("/api/invites/{$oldRaw}")->assertStatus(410);
        $this->getJson("/api/invites/{$newRaw}")
            ->assertOk()
            ->assertJsonPath('valid', true);
    }

    public function test_non_admin_cannot_regenerate_invite(): void
    {
        $admin = $this->makeAdmin();
        $created = $this->postJson('/api/invites', [
            'email' => 'authz-'.Str::random(8).'@example.com',
        ])->assertCreated()->json('data');

        $this->postJson('/api/auth/logout')->assertOk();

        $user = User::query()->create([
            'name' => 'User',
            'email' => 'user2-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $this->postJson("/api/invites/{$created['id']}/regenerate")->assertForbidden();
        $this->assertSame(
            hash('sha256', $this->rawTokenFromCreatePayload($created)),
            UserInvite::query()->find($created['id'])?->token
        );
        unset($admin);
    }

    public function test_login_with_pending_invite_does_not_disclose_token_or_authenticate(): void
    {
        $this->makeAdmin();
        $email = 'pending-'.Str::random(8).'@example.com';

        $this->postJson('/api/invites', ['email' => $email])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();

        $response = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'anything',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('invite_setup_required', true)
            ->assertJsonMissing(['invite_token'])
            ->assertJsonPath(
                'message',
                'An invitation is pending for this email. Please use the invitation link provided by your administrator.'
            );

        $this->assertGuest('web');
        $this->assertArrayNotHasKey('invite_token', $response->json());
        $this->getJson('/api/auth/me')->assertOk()->assertJson(['user' => null]);
    }

    public function test_registered_user_login_unchanged(): void
    {
        $user = User::query()->create([
            'name' => 'Existing',
            'email' => 'existing-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissing(['invite_token', 'invite_setup_required']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_revoke_invite(): void
    {
        $this->makeAdmin();
        $created = $this->postJson('/api/invites', [
            'email' => 'revoke-'.Str::random(8).'@example.com',
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/invites/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('portfolio_user_invites', ['id' => $created['id']]);
    }

    public function test_hash_token_helper_matches_storage(): void
    {
        $service = app(UserInviteService::class);
        $raw = Str::random(64);
        $this->assertSame(64, strlen($service->hashToken($raw)));
        $this->assertSame(hash('sha256', $raw), $service->hashToken($raw));
    }
}
