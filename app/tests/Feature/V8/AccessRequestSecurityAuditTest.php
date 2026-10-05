<?php

namespace Tests\Feature\V8;

use App\Services\AccessRequest\HumanVerificationService;
use App\Services\AccessRequest\TestingHumanVerificationService;
use App\Services\AccessRequest\TurnstileHumanVerificationService;
use App\Mail\AccessRequestVerificationMail;
use App\Models\AccessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessRequestSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_cannot_select_testing_captcha_driver(): void
    {
        config([
            'app.env' => 'production',
            'access_requests.captcha.driver' => 'testing',
        ]);

        $service = app(HumanVerificationService::class);

        $this->assertInstanceOf(TurnstileHumanVerificationService::class, $service);
        $this->assertNotInstanceOf(TestingHumanVerificationService::class, $service);
    }

    public function test_non_production_testing_driver_remains_available_for_tests(): void
    {
        config([
            'app.env' => 'testing',
            'access_requests.captcha.driver' => 'testing',
        ]);

        $this->assertInstanceOf(TestingHumanVerificationService::class, app(HumanVerificationService::class));
    }

    public function test_duplicate_submission_does_not_create_another_verification_or_pending_request(): void
    {
        Mail::fake();
        config(['access_requests.captcha.driver' => 'testing']);
        $email = 'race-'.Str::random(8).'@example.com';

        $payload = ['full_name' => 'Applicant', 'email' => $email, 'captcha_token' => 'ok'];
        $this->postJson('/api/auth/access-requests', $payload)->assertOk();
        $this->postJson('/api/auth/access-requests', $payload)->assertOk();

        Mail::assertSent(AccessRequestVerificationMail::class, 1);
        $this->assertDatabaseCount('stox_access_requests', 1);
        $this->assertDatabaseCount('stox_access_request_verifications', 1);

        $request = AccessRequest::query()->where('email_normalized', Str::lower($email))->firstOrFail();
        $this->assertSame(AccessRequest::VERIFICATION_UNVERIFIED, $request->verification_status);
        $this->assertSame(AccessRequest::STATUS_PENDING, $request->status);
    }
}
