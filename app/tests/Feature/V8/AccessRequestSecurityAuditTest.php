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

    public function test_two_verification_tokens_for_one_email_create_one_pending_request(): void
    {
        Mail::fake();
        config(['access_requests.captcha.driver' => 'testing']);
        $email = 'race-'.Str::random(8).'@example.com';

        $payload = ['full_name' => 'Applicant', 'email' => $email, 'captcha_token' => 'ok'];
        $this->postJson('/api/auth/access-requests', $payload)->assertOk();
        $this->postJson('/api/auth/access-requests', $payload)->assertOk();

        $tokens = [];
        Mail::assertSent(AccessRequestVerificationMail::class, function (AccessRequestVerificationMail $mail) use (&$tokens): bool {
            $tokens[] = Str::afterLast($mail->verificationUrl, '/verify/');

            return true;
        });
        $this->assertCount(2, $tokens);

        $this->postJson('/api/auth/access-requests/verify/'.$tokens[0])->assertJsonPath('status', 'pending');
        $this->postJson('/api/auth/access-requests/verify/'.$tokens[1])->assertJsonPath('status', 'blocked');
        $this->assertSame(1, AccessRequest::query()->where('email_normalized', Str::lower($email))->count());
    }
}
