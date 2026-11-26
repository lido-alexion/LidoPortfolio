<?php

namespace Tests\Feature;

use App\Auth\ContextualSessionGuard;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSessionContextTest extends TestCase
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
        $this->withCredentials();
    }

    public function test_admin_and_investor_sessions_use_separate_cookies_and_remember_tokens(): void
    {
        $investor = $this->user(false);
        $admin = $this->user(true);

        $investorLogin = $this->postJson('/api/auth/login', [
            'email' => $investor->email,
            'password' => 'secret-password',
            'remember' => true,
        ])->assertOk();
        $investorSessionCookie = $investorLogin->getCookie(config('session.cookie'));
        $this->assertNotNull($investorSessionCookie);

        $adminLogin = $this->withHeader('X-Stox-Auth-Context', 'admin')->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
            'remember' => true,
        ])->assertOk();
        $adminSessionCookie = $adminLogin->getCookie(config('session.cookie'));
        $this->assertNotNull($adminSessionCookie);
        $this->assertNotSame($investorSessionCookie->getValue(), $adminSessionCookie->getValue());
        $this->assertNotNull($adminLogin->getCookie('remember_admin_web_'.sha1(ContextualSessionGuard::class), false));
        $this->assertNotNull($investorLogin->getCookie('remember_web_'.sha1(\Illuminate\Auth\SessionGuard::class), false));
        $this->assertNotNull($investorLogin->getCookie('remember_web_'.sha1(SessionGuard::class), false));
        $legacyRecaller = $investorLogin->getCookie('remember_web_'.sha1(SessionGuard::class));
        $legacyRecallerParts = explode('|', $legacyRecaller->getValue());
        $this->assertCount(3, $legacyRecallerParts);
        $this->assertSame((string) $investor->id, $legacyRecallerParts[0]);
        $this->assertSame(
            hash_hmac('sha256', $investor->getAuthPassword(), config('app.key')),
            $legacyRecallerParts[2],
            'Remember-me login must emit the legacy recaller payload with Laravel’s configured HMAC key.',
        );

        $this->withCookies([
            $this->baseCookieName() => $investorSessionCookie->getValue(),
            $this->baseCookieName().'-admin' => $adminSessionCookie->getValue(),
        ]);
        $this->withHeader('X-Stox-Auth-Context', 'investor')
            ->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $investor->id);
        $this->withHeader('X-Stox-Auth-Context', 'admin')
            ->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);

        $this->withHeader('X-Stox-Auth-Context', 'investor')->postJson('/api/auth/logout')->assertOk();
        $this->withHeader('X-Stox-Auth-Context', 'admin')
            ->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);

        $this->withHeader('X-Stox-Auth-Context', 'investor')
            ->getJson('/api/portfolios')->assertUnauthorized();
    }

    public function test_investor_guard_reads_legacy_session_key_and_uses_framework_recaller_hmac(): void
    {
        $investor = $this->user(false);
        $guard = Auth::guard('web');
        $legacySessionKey = 'login_web_'.sha1(SessionGuard::class);

        $this->assertSame($legacySessionKey, $guard->getName());
        $this->assertSame('remember_web_'.sha1(SessionGuard::class), $guard->getRecallerName());

        // This is the session entry emitted by Laravel's built-in SessionGuard
        // before the contextual guard was installed.
        $this->withSession([$legacySessionKey => $investor->getAuthIdentifier()]);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $investor->id);

        $configuredKey = config('app.key');
        $this->assertSame(
            hash_hmac('sha256', $investor->getAuthPassword(), $configuredKey),
            Auth::guard('web')->hashPasswordForCookie($investor->getAuthPassword()),
            'The recaller HMAC must use Laravel’s configured application key.',
        );
    }

    public function test_wrong_role_cannot_be_established_in_selected_context(): void
    {
        $admin = $this->user(true);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertForbidden();

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user', null);
    }

    public function test_invalid_context_header_fails_closed(): void
    {
        $this->withHeader('X-Stox-Auth-Context', 'administrator')
            ->getJson('/api/auth/me')->assertBadRequest();
    }

    public function test_csrf_tokens_are_bound_to_each_session_context(): void
    {
        $investorToken = $this->withHeader('X-Stox-Auth-Context', 'investor')
            ->getJson('/api/auth/csrf-token')->assertOk()->json('token');
        $adminToken = $this->withHeader('X-Stox-Auth-Context', 'admin')
            ->getJson('/api/auth/csrf-token')->assertOk()->json('token');

        $this->assertNotSame($investorToken, $adminToken);
    }

    public function test_admin_cookie_is_not_used_as_fallback_for_investor_context(): void
    {
        $admin = $this->user(true);
        $response = $this->withHeader('X-Stox-Auth-Context', 'admin')->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk();
        $adminCookie = $response->getCookie($this->baseCookieName().'-admin');

        $this->withCookies([$this->baseCookieName().'-admin' => $adminCookie->getValue()])
            ->withHeader('X-Stox-Auth-Context', 'investor')
            ->getJson('/api/portfolios')->assertUnauthorized();
    }

    public function test_same_role_cookie_replacement_rejects_a_stale_tabs_identity_before_controller(): void
    {
        $oldPrincipal = $this->user(false);
        $newPrincipal = $this->user(false);

        $oldLogin = $this->postJson('/api/auth/login', [
            'email' => $oldPrincipal->email,
            'password' => 'secret-password',
        ])->assertOk();
        $oldCookie = $oldLogin->getCookie($this->baseCookieName());

        $newLogin = $this->postJson('/api/auth/login', [
            'email' => $newPrincipal->email,
            'password' => 'secret-password',
        ])->assertOk();
        $newCookie = $newLogin->getCookie($this->baseCookieName());

        $this->withCookies([$this->baseCookieName() => $newCookie->getValue()])
            ->withHeader('X-Stox-Auth-User', (string) $oldPrincipal->id)
            ->getJson('/api/notification-settings')
            ->assertUnauthorized();

        $this->withCookies([$this->baseCookieName() => $oldCookie->getValue()])
            ->withHeader('X-Stox-Auth-User', 'guest')
            ->getJson('/api/notification-settings')
            ->assertUnauthorized();
    }

    public function test_authenticated_routes_enforce_role_context_without_portfolio_middleware(): void
    {
        $investor = $this->user(false);
        $this->actingAs($investor, 'web')
            ->withHeader('X-Stox-Auth-Context', 'admin')
            ->withHeader('X-Stox-Auth-User', (string) $investor->id)
            ->getJson('/api/notification-settings')
            ->assertForbidden();
    }

    private function user(bool $admin): User
    {
        $user = User::query()->create([
            'name' => $admin ? 'Admin' : 'Investor',
            'email' => ($admin ? 'admin-' : 'investor-').Str::random(10).'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $user->forceFill(['is_admin' => $admin])->save();

        return $user->fresh();
    }

    private function baseCookieName(): string
    {
        return (string) (env('SESSION_COOKIE') ?: Str::slug((string) config('app.name', 'laravel')).'-session');
    }
}
