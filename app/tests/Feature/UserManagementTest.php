<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManagementTest extends TestCase
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
}
