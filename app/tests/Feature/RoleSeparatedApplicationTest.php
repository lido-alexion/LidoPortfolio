<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\PortfolioProfileService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoleSeparatedApplicationTest extends TestCase
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

    protected function makeUser(bool $admin = false): User
    {
        $user = User::query()->create([
            'name' => $admin ? 'Admin' : 'Investor',
            'email' => Str::random(10).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->forceFill(['is_admin' => $admin])->save();

        return $user->fresh();
    }

    protected function login(User $user): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_admin_login_does_not_create_a_portfolio_and_reports_no_default(): void
    {
        $admin = $this->makeUser(true);

        $this->login($admin);

        $this->assertDatabaseMissing('portfolio_profiles', ['user_id' => $admin->id]);
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.is_admin', true)
            ->assertJsonPath('user.default_portfolio_id', null);
    }

    public function test_admin_is_rejected_from_investor_portfolio_api_without_creating_state(): void
    {
        $admin = $this->makeUser(true);
        $this->login($admin);

        $this->getJson('/api/portfolios')
            ->assertForbidden()
            ->assertJsonPath('message', 'Investor application access is not available to Admin accounts.');
        $this->postJson('/api/portfolios', ['name' => 'Forbidden'])
            ->assertForbidden();

        $this->assertSame(0, PortfolioProfile::query()->where('user_id', $admin->id)->count());
    }

    public function test_admin_can_still_use_admin_and_shared_session_apis(): void
    {
        $admin = $this->makeUser(true);
        $this->login($admin);

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/auth/sessions')->assertOk();
        $this->getJson('/api/profile')->assertOk();
    }

    public function test_investor_still_receives_a_default_portfolio_and_cannot_use_admin_api(): void
    {
        $investor = $this->makeUser();
        $this->login($investor);

        $this->getJson('/api/portfolios')->assertOk();
        $this->assertSame(1, PortfolioProfile::query()->where('user_id', $investor->id)->count());
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_service_refuses_direct_default_portfolio_creation_for_admin(): void
    {
        $admin = $this->makeUser(true);

        $this->expectException(ValidationException::class);
        app(PortfolioProfileService::class)->createDefaultForUser($admin);
    }
}
