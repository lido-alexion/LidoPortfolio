<?php

namespace Tests\Feature\V8;

use App\Models\AccessRequest;
use App\Models\AccessRequestBan;
use App\Models\User;
use App\Mail\AccessRequestVerificationMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessRequestWorkflowTest extends TestCase
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
        Mail::fake();
        config(['access_requests.captcha.driver' => 'testing']);
        config(['access_requests.ignore_cooldown_hours' => 1]);
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

    public function test_verified_request_flow_and_admin_create(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $email = 'applicant-'.Str::random(6).'@example.com';

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Applicant Name',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();

        $this->assertDatabaseCount('portfolio_access_requests', 0);
        $raw = $this->verificationTokenFromMail();
        $this->postJson('/api/auth/access-requests/verify/'.$raw)->assertOk();

        $this->assertDatabaseHas('portfolio_access_requests', [
            'email_normalized' => Str::lower($email),
            'status' => AccessRequest::STATUS_PENDING,
        ]);

        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->firstOrFail();

        $this->actingAsUser($admin)
            ->postJson('/api/access-requests/'.$request->id.'/create-invite')
            ->assertOk();

        $request->refresh();
        $this->assertSame(AccessRequest::STATUS_CREATED, $request->status);
        $this->assertNotNull($request->user_invite_id);

        $detail = $this->getJson('/api/access-requests/'.$request->id)
            ->assertOk()
            ->json();

        $this->assertNotEmpty($detail['history']);
        $this->assertNotEmpty($detail['audit_events']);
        $this->assertTrue(collect($detail['audit_events'])->contains(fn ($e) => $e['event_type'] === 'admin_create'));
    }

    public function test_captcha_failure_creates_no_verification(): void
    {
        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Applicant',
            'email' => 'captcha@example.com',
            'captcha_token' => '',
        ])->assertStatus(422);

        $this->assertDatabaseCount('portfolio_access_request_verifications', 0);
    }

    public function test_non_admin_cannot_list_access_requests(): void
    {
        $user = $this->makeUser();
        $this->actingAsUser($user)
            ->getJson('/api/access-requests')
            ->assertForbidden();
    }

    public function test_admin_access_request_list_accepts_string_boolean_query_values(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        AccessRequest::query()->create([
            'full_name' => 'Pending Applicant',
            'email_normalized' => 'pending-'.Str::random(6).'@example.com',
            'status' => AccessRequest::STATUS_PENDING,
            'verified_at' => now(),
        ]);
        AccessRequest::query()->create([
            'full_name' => 'Resolved Applicant',
            'email_normalized' => 'resolved-'.Str::random(6).'@example.com',
            'status' => AccessRequest::STATUS_IGNORED,
            'verified_at' => now(),
        ]);

        $this->actingAsUser($admin)
            ->getJson('/api/access-requests?pending_only=false')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pending_count', 1);

        $this->getJson('/api/access-requests?pending_only=true')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_reject_pending_request(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $request = AccessRequest::query()->create([
            'full_name' => 'Applicant',
            'email_normalized' => 'solo-'.Str::random(6).'@example.com',
            'status' => AccessRequest::STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->actingAsUser($admin)
            ->postJson('/api/access-requests/'.$request->id.'/reject')
            ->assertOk();
    }

    public function test_reject_creates_ban_and_clear_allows_resubmit(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $email = 'reject-'.Str::random(6).'@example.com';

        $this->submitAndVerify($email);

        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->firstOrFail();
        $this->actingAsUser($admin)
            ->postJson('/api/access-requests/'.$request->id.'/reject', ['reason' => 'internal'])
            ->assertOk();

        $this->assertDatabaseHas('portfolio_access_request_audit_events', [
            'access_request_id' => $request->id,
            'event_type' => 'admin_reject',
        ]);

        $ban = AccessRequestBan::query()->where('email_normalized', Str::lower($email))->whereNull('cleared_at')->first();
        $this->assertNotNull($ban);

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Again',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();
        $this->assertDatabaseCount('portfolio_access_request_verifications', 1);

        $this->actingAsUser($admin)
            ->postJson('/api/access-request-bans/'.$ban->id.'/clear')
            ->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Again',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();
        $this->assertDatabaseCount('portfolio_access_request_verifications', 2);
    }

    public function test_existing_user_does_not_create_verification(): void
    {
        $existing = $this->makeUser(['email' => 'exists@example.com']);

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'X',
            'email' => $existing->email,
            'captcha_token' => 'ok',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email is eligible for an access request, a verification email will be sent shortly. If you already have an account, use the login page.');

        $this->assertDatabaseCount('portfolio_access_request_verifications', 0);
    }

    public function test_concurrent_create_invite_only_one_succeeds(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $adminTwo = $this->makeUser(['is_admin' => true, 'email' => 'admin2-'.Str::random(6).'@example.com']);
        $email = 'concurrent-'.Str::random(6).'@example.com';
        $this->submitAndVerify($email);
        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->firstOrFail();

        $this->actingAsUser($admin);
        $first = $this->postJson('/api/access-requests/'.$request->id.'/create-invite');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->actingAsUser($adminTwo);
        $second = $this->postJson('/api/access-requests/'.$request->id.'/create-invite');

        $statuses = [$first->status(), $second->status()];
        $this->assertContains(200, $statuses);
        $this->assertContains(422, $statuses);
        $this->assertSame(1, \App\Models\UserInvite::query()->where('email', Str::lower($email))->count());
    }

    public function test_ignore_establishes_cooldown(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $email = 'cooldown-'.Str::random(6).'@example.com';
        $this->submitAndVerify($email);
        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->firstOrFail();

        $this->actingAsUser($admin)
            ->postJson('/api/access-requests/'.$request->id.'/ignore')
            ->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Again',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();

        $this->assertDatabaseCount('portfolio_access_request_verifications', 1);

        $request->refresh();
        $this->assertNotNull($request->resubmit_allowed_after);
        $this->travel(2)->hours();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Again',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();
        $this->assertDatabaseCount('portfolio_access_request_verifications', 2);
    }

    public function test_pending_invite_blocks_new_verification(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $email = 'invited-'.Str::random(6).'@example.com';
        $this->actingAsUser($admin)->postJson('/api/invites', ['email' => $email])->assertCreated();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'X',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();

        $this->assertDatabaseCount('portfolio_access_request_verifications', 0);
    }

    protected function submitAndVerify(string $email): void
    {
        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Applicant',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();

        $raw = $this->verificationTokenFromMail();
        $this->postJson('/api/auth/access-requests/verify/'.$raw)->assertOk();
    }

    protected function verificationTokenFromMail(): string
    {
        $url = null;
        Mail::assertSent(AccessRequestVerificationMail::class, function (AccessRequestVerificationMail $mail) use (&$url) {
            $url = $mail->verificationUrl;

            return true;
        });
        $this->assertNotNull($url);

        return Str::afterLast((string) $url, '/verify/');
    }
}
