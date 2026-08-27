<?php

namespace Tests\Feature\Totp;

use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TotpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_enrollment_invalid_code_and_successful_activation(): void
    {
        [$user] = $this->actingUser();

        $this->getJson('/api/v1/totp')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $begin = $this->postJson('/api/v1/totp/begin')->assertOk();
        $this->assertNotEmpty($begin->json('data.secret'));
        $this->assertStringStartsWith('otpauth://totp/', (string) $begin->json('data.otpauth_url'));
        $this->assertStringContainsString('<svg', (string) $begin->json('data.qr_svg'));

        $this->postJson('/api/v1/totp/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOTP_INVALID');

        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $confirm = $this->postJson('/api/v1/totp/confirm', ['code' => $otp])
            ->assertOk();
        $codes = $confirm->json('data.recovery_codes');
        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);

        $status = $this->getJson('/api/v1/totp')->assertOk();
        $this->assertTrue($status->json('data.enabled'));
        $this->assertArrayNotHasKey('secret', $status->json('data'));

        $this->assertTrue($user->fresh()->totpIsActive());
        $this->assertNull($user->fresh()->toArray()['totp_secret'] ?? null);
        $storedHashes = $user->fresh()->totp_recovery_codes;
        $this->assertIsArray($storedHashes);
        foreach ($codes as $plain) {
            $this->assertNotContains($plain, $storedHashes);
            $this->assertTrue(collect($storedHashes)->contains(fn ($hash) => Hash::check($plain, $hash)));
        }
    }

    public function test_secrets_and_recovery_codes_are_not_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event;
        });

        [$user] = $this->actingUser();

        $begin = $this->postJson('/api/v1/totp/begin')->assertOk();
        $secret = (string) $begin->json('data.secret');
        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $codes = $this->postJson('/api/v1/totp/confirm', ['code' => $otp])
            ->assertOk()
            ->json('data.recovery_codes');

        $encoded = json_encode($logged);
        $this->assertStringNotContainsString($secret, (string) $encoded);
        foreach ($codes as $plain) {
            $this->assertStringNotContainsString($plain, (string) $encoded);
        }
    }

    public function test_valid_and_invalid_verify_and_replay(): void
    {
        [$user] = $this->actingUser();
        $this->enroll($user);
        $user->forceFill(['totp_last_counter' => null])->save();

        $this->postJson('/api/v1/totp/verify', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOTP_INVALID');

        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $this->postJson('/api/v1/totp/verify', ['code' => $otp])->assertOk();
        $this->postJson('/api/v1/totp/verify', ['code' => $otp])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOTP_INVALID');
    }

    public function test_verify_is_rate_limited(): void
    {
        [$user] = $this->actingUser();
        $this->enroll($user);
        RateLimiter::clear(TotpService::RATE_LIMIT_KEY_PREFIX.$user->id);

        for ($i = 0; $i < TotpService::RATE_LIMIT_MAX; $i++) {
            $this->postJson('/api/v1/totp/verify', ['code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/v1/totp/verify', ['code' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOTP_RATE_LIMITED');
    }

    public function test_recovery_code_is_single_use_and_disable_works(): void
    {
        [$user] = $this->actingUser();
        $codes = $this->enroll($user);

        $this->postJson('/api/v1/totp/recover', ['code' => $codes[0]])->assertOk();
        $this->postJson('/api/v1/totp/recover', ['code' => $codes[0]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOTP_RECOVERY_INVALID');

        $user->forceFill(['totp_last_counter' => null])->save();
        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $this->postJson('/api/v1/totp/disable', ['code' => $otp])->assertOk();
        $this->assertFalse($user->fresh()->totpIsActive());
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile}
     */
    protected function actingUser(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        return [$user, $profile];
    }

    /**
     * @return list<string>
     */
    protected function enroll(User $user): array
    {
        $this->postJson('/api/v1/totp/begin')->assertOk();
        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $codes = $this->postJson('/api/v1/totp/confirm', ['code' => $otp])
            ->assertOk()
            ->json('data.recovery_codes');

        return $codes;
    }
}
