<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileTest extends TestCase
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

    protected function actingAsPortfolioUser(): User
    {
        $user = User::query()->create([
            'name' => 'Profile User',
            'email' => 'profile-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $user;
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_profile_show_returns_user_fields(): void
    {
        $user = $this->actingAsPortfolioUser();

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.name', $user->name);
    }

    public function test_profile_update_name(): void
    {
        $this->actingAsPortfolioUser();

        $this->putJson('/api/profile', ['name' => 'New Display Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Display Name');

        $this->putJson('/api/profile', ['name' => ''])
            ->assertOk()
            ->assertJsonPath('data.name', '');
    }

    public function test_profile_update_password(): void
    {
        $user = $this->actingAsPortfolioUser();

        $this->putJson('/api/profile/password', [
            'current_password' => 'password123',
            'password' => 'new-password-99',
            'password_confirmation' => 'new-password-99',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-99', $user->password));
    }

    public function test_profile_update_password_rejects_wrong_current_password(): void
    {
        $this->actingAsPortfolioUser();

        $this->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-99',
            'password_confirmation' => 'new-password-99',
        ])->assertUnprocessable();
    }

    public function test_profile_photo_upload_and_delete(): void
    {
        $user = $this->actingAsPortfolioUser();

        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $this->postJson('/api/profile/photo', ['photo' => $file])
            ->assertOk()
            ->assertJsonStructure(['data' => ['profile_photo_url']]);

        $user->refresh();
        $this->assertNotNull($user->profile_photo_path);
        $this->assertStringContainsString('/api/profile/photo?v=', (string) $user->profile_photo_url);

        $this->get('/api/profile/photo')
            ->assertOk();

        $this->deleteJson('/api/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.profile_photo_url', null);

        $user->refresh();
        $this->assertNull($user->profile_photo_path);
    }

    public function test_me_includes_profile_photo_url_accessor(): void
    {
        $user = $this->actingAsPortfolioUser();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.profile_photo_path');
    }
}
