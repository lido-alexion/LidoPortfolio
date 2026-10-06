<?php

namespace Tests\Feature\V8;

use App\Models\AccessRequest;
use App\Models\AccessRequestVerification;
use App\Models\AccessRequestBan;
use App\Models\User;
use App\Mail\AccessRequestVerificationMail;
use App\Mail\UserInvitationMail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_access_request_config_exposes_only_the_turnstile_site_key(): void
    {
        config(['access_requests.captcha.turnstile.site_key' => '0x4AAAA']);

        $this->getJson('/api/auth/access-requests/config')
            ->assertOk()
            ->assertJsonPath('data.turnstile_site_key', '0x4AAAA')
            ->assertJsonMissingPath('data.turnstile_secret_key');
    }

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

        $this->assertDatabaseCount('stox_access_requests', 1);
        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->sole();
        $this->assertSame(AccessRequest::STATUS_PENDING, $request->status);
        $this->assertSame(AccessRequest::VERIFICATION_UNVERIFIED, $request->verification_status);
        $this->assertNull($request->verified_at);
        $raw = $this->verificationTokenFromMail();
        $this->postJson('/api/auth/access-requests/verify/'.$raw)->assertOk();

        $this->assertDatabaseHas('stox_access_requests', [
            'email_normalized' => Str::lower($email),
            'status' => AccessRequest::STATUS_PENDING,
        ]);

        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->firstOrFail();
        $this->assertSame(AccessRequest::VERIFICATION_VERIFIED, $request->verification_status);

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

    public function test_distinct_valid_verification_tokens_for_one_request_only_verify_once(): void
    {
        $email = 'two-tokens-'.Str::random(8).'@example.com';

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Applicant',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();

        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->sole();
        $firstToken = $this->verificationTokenFromMail();
        $secondToken = Str::random(64);

        $secondVerification = AccessRequestVerification::query()->create([
            'access_request_id' => $request->id,
            'full_name' => $request->full_name,
            'email_normalized' => $request->email_normalized,
            'token_hash' => hash('sha256', $secondToken),
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/auth/access-requests/verify/'.$firstToken)->assertOk();
        $this->postJson('/api/auth/access-requests/verify/'.$secondToken)->assertUnprocessable();

        $this->assertSame(AccessRequest::VERIFICATION_VERIFIED, $request->fresh()->verification_status);
        $this->assertDatabaseCount('stox_access_request_audit_events', 2);
        $this->assertNotNull($secondVerification->fresh()->used_at);
    }

    public function test_captcha_failure_creates_no_verification(): void
    {
        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Applicant',
            'email' => 'captcha@example.com',
            'captcha_token' => '',
        ])->assertStatus(422);

        $this->assertDatabaseCount('stox_access_request_verifications', 0);
    }

    public function test_verification_mail_failure_keeps_the_immediately_visible_request(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp unavailable'));
        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Applicant', 'email' => 'verify-fail-'.Str::random(6).'@example.com', 'captcha_token' => 'ok',
        ])->assertOk();
        $request = AccessRequest::query()->where('status', AccessRequest::STATUS_PENDING)->sole();
        $verification = \App\Models\AccessRequestVerification::query()->where('access_request_id', $request->id)->sole();
        $this->assertSame('unverified', $request->verification_status);
        $this->assertSame('failed', $verification->email_delivery_status);
        $this->assertDatabaseHas('stox_access_requests', ['id' => $request->id]);
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

        $this->assertDatabaseHas('stox_access_request_audit_events', [
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
        $this->assertDatabaseCount('stox_access_request_verifications', 1);

        $this->actingAsUser($admin)
            ->postJson('/api/access-request-bans/'.$ban->id.'/clear')
            ->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Again',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();
        $this->assertDatabaseCount('stox_access_request_verifications', 2);
    }

    public function test_existing_user_does_not_create_verification(): void
    {
        $existing = $this->makeUser(['email' => 'exists@example.com']);

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'X',
            'email' => $existing->email,
            'captcha_token' => 'ok',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email is eligible, an administrator can review the access request. If you already use StoX, please use the login page.');

        $this->assertDatabaseCount('stox_access_request_verifications', 0);
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

        $this->assertDatabaseCount('stox_access_request_verifications', 1);

        $request->refresh();
        $this->assertNotNull($request->resubmit_allowed_after);
        $this->travel(2)->hours();

        $this->postJson('/api/auth/access-requests', [
            'full_name' => 'Again',
            'email' => $email,
            'captcha_token' => 'ok',
        ])->assertOk();
        $this->assertDatabaseCount('stox_access_request_verifications', 2);
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

        $this->assertDatabaseCount('stox_access_request_verifications', 0);
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

    public function test_admin_can_approve_unverified_and_audit_explicit_decision(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $this->postJson('/api/auth/access-requests', ['full_name' => 'Applicant', 'email' => 'unverified-'.Str::random(6).'@example.com', 'captcha_token' => 'ok'])->assertOk();
        $request = AccessRequest::query()->where('status', AccessRequest::STATUS_PENDING)->sole();

        $this->actingAsUser($admin)->postJson('/api/access-requests/'.$request->id.'/create-invite')->assertUnprocessable();
        $this->postJson('/api/access-requests/'.$request->id.'/create-invite', ['confirm_unverified' => true])->assertOk();
        $copy = $this->getJson('/api/access-requests/'.$request->id.'/invitation-copy')->assertOk()->json('data');
        $this->assertStringContainsString($copy['invite_url'], $copy['invite_message']);
        Mail::assertSent(UserInvitationMail::class, fn (UserInvitationMail $mail) => str_contains($mail->plainTextBody, $copy['invite_url']));
        $invite = $request->fresh()->userInvite;
        $invite->update(['email_delivery_status' => 'failed', 'email_last_error_code' => 'MAIL_DELIVERY_FAILED']);
        $this->actingAs($admin)->postJson('/api/invites/'.$invite->id.'/retry-email')->assertOk();
        $retryCopy = $this->getJson('/api/access-requests/'.$request->id.'/invitation-copy')->assertOk()->json('data');
        $this->assertSame($copy['invite_url'], $retryCopy['invite_url']);
        $event = \App\Models\AccessRequestAuditEvent::query()->where('access_request_id', $request->id)->where('event_type', 'admin_approval')->first();
        $this->assertNotNull($event);
        $this->assertSame('unverified', $event->context['verification_state']);
        $this->assertSame($admin->id, $event->context['admin_id']);
        $this->assertNotEmpty($event->context['approved_at']);
    }

    public function test_unverified_timeout_expires_without_deleting_and_verified_request_survives(): void
    {
        $this->postJson('/api/auth/access-requests', ['full_name' => 'Applicant', 'email' => 'expire-'.Str::random(6).'@example.com', 'captcha_token' => 'ok'])->assertOk();
        $pending = AccessRequest::query()->where('status', AccessRequest::STATUS_PENDING)->sole();
        $verified = AccessRequest::query()->create(['full_name' => 'Verified', 'email_normalized' => 'verified-'.Str::random(6).'@example.com', 'status' => AccessRequest::STATUS_PENDING, 'verification_status' => 'verified', 'verified_at' => now()->subDays(9), 'created_at' => now()->subDays(9)]);
        app(\App\Services\AccessRequest\AccessRequestVerificationService::class)->expireUnverifiedRequests();
        $this->travel(8)->days();
        app(\App\Services\AccessRequest\AccessRequestVerificationService::class)->expireUnverifiedRequests();
        $this->assertSame('expired', $pending->fresh()->status);
        $this->assertSame('unverified_timeout', $pending->fresh()->expiry_reason);
        $this->assertDatabaseHas('stox_access_request_audit_events', [
            'access_request_id' => $pending->id,
            'event_type' => 'request_expired',
        ]);
        $this->assertSame('pending', $verified->fresh()->status);
        $this->assertDatabaseHas('stox_access_requests', ['id' => $pending->id]);
    }

    public function test_resend_verification_reuses_same_business_request(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $this->postJson('/api/auth/access-requests', ['full_name' => 'Applicant', 'email' => 'resend-'.Str::random(6).'@example.com', 'captcha_token' => 'ok'])->assertOk();
        $request = AccessRequest::query()->where('status', AccessRequest::STATUS_PENDING)->sole();
        $this->actingAsUser($admin)->postJson('/api/access-requests/'.$request->id.'/resend-verification')->assertOk();
        $this->assertDatabaseCount('stox_access_requests', 1);
        $this->assertDatabaseCount('stox_access_request_verifications', 2);
        $this->assertDatabaseHas('stox_access_request_verifications', ['access_request_id' => $request->id]);
    }

    public function test_late_verification_cannot_resurrect_an_expired_request(): void
    {
        $request = AccessRequest::query()->create([
            'full_name' => 'Applicant', 'email_normalized' => 'late-'.Str::random(6).'@example.com',
            'status' => AccessRequest::STATUS_EXPIRED, 'verification_status' => 'unverified',
            'expiry_reason' => 'unverified_timeout', 'created_at' => now()->subDays(8),
        ]);
        $token = Str::random(64);
        \App\Models\AccessRequestVerification::query()->create([
            'access_request_id' => $request->id, 'full_name' => $request->full_name,
            'email_normalized' => $request->email_normalized, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/auth/access-requests/verify/'.$token)->assertUnprocessable();
        $this->assertSame(AccessRequest::STATUS_EXPIRED, $request->fresh()->status);
        $this->assertSame('unverified', $request->fresh()->verification_status);
    }

    public function test_invitation_queue_failure_keeps_created_request_and_same_invite_retry_material(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $this->postJson('/api/auth/access-requests', ['full_name' => 'Applicant', 'email' => 'mail-fail-'.Str::random(6).'@example.com', 'captcha_token' => 'ok'])->assertOk();
        $request = AccessRequest::query()->where('status', AccessRequest::STATUS_PENDING)->sole();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp unavailable'));

        $this->actingAsUser($admin)->postJson('/api/access-requests/'.$request->id.'/create-invite', ['confirm_unverified' => true])->assertOk();
        $request->refresh();
        $invite = $request->userInvite()->firstOrFail();
        $encryptedToken = $invite->token_encrypted;
        $this->assertSame(AccessRequest::STATUS_CREATED, $request->status);
        $this->assertSame('failed', $invite->email_delivery_status);
        $this->assertSame('MAIL_DELIVERY_FAILED', $invite->email_last_error_code);
        $this->assertNotEmpty($encryptedToken);
        $this->assertSame($invite->token, app(\App\Services\UserInviteService::class)->hashToken(Crypt::decryptString($encryptedToken)));
    }
}
