<?php

namespace App\Services\Security;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\PortfolioLoggerService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator TOTP (V4-FEAT-001). Secrets and recovery codes are never logged.
 */
class TotpService
{
    public const RATE_LIMIT_KEY_PREFIX = 'totp-verify:';

    public const RATE_LIMIT_MAX = 5;

    public const RATE_LIMIT_DECAY_SECONDS = 60;

    public const WINDOW = 1;

    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        protected Google2FA $google2fa,
        protected PortfolioLoggerService $logger,
    ) {
        $this->google2fa->setWindow(self::WINDOW);
    }

    /**
     * @return array{enabled:bool,pending:bool,confirmed_at:?string}
     */
    public function status(User $user): array
    {
        return [
            'enabled' => $user->totpIsActive(),
            'pending' => is_string($user->totp_pending_secret) && $user->totp_pending_secret !== '',
            'confirmed_at' => $user->totp_confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{secret:string,otpauth_url:string,qr_svg:string}
     */
    public function beginEnrollment(User $user): array
    {
        if ($user->totpIsActive()) {
            throw new DomainException(
                'Authenticator is already enabled. Disable it before enrolling again.',
                'TOTP_ALREADY_ENABLED',
                422,
            );
        }

        $secret = $this->google2fa->generateSecretKey();
        $user->forceFill([
            'totp_pending_secret' => $secret,
        ])->save();

        $otpauth = $this->google2fa->getQRCodeUrl(
            (string) config('app.name', 'Lido'),
            (string) $user->email,
            $secret,
        );

        $this->logger->event('TotpService', 'totp.enrollment_started', 'info', 'TOTP enrollment started', [
            'user_id' => $user->id,
        ]);

        return [
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'qr_svg' => $this->qrSvg($otpauth),
        ];
    }

    /**
     * @return array{recovery_codes: list<string>}
     */
    public function confirmEnrollment(User $user, #[\SensitiveParameter] string $code): array
    {
        $user->refresh();
        $pending = $user->totp_pending_secret;
        if (! is_string($pending) || $pending === '') {
            throw new DomainException(
                'Start authenticator enrollment before confirming.',
                'TOTP_ENROLLMENT_NOT_STARTED',
                422,
            );
        }

        $this->assertNotRateLimited($user);
        $timestamp = $this->google2fa->verifyKeyNewer($pending, $this->normalizeCode($code), null, self::WINDOW);
        if ($timestamp === false) {
            $this->hitRateLimit($user);
            throw new DomainException('Invalid authenticator code.', 'TOTP_INVALID', 422);
        }

        $this->clearRateLimit($user);
        $recovery = $this->generateRecoveryCodes();
        $user->forceFill([
            'totp_secret' => $pending,
            'totp_pending_secret' => null,
            'totp_confirmed_at' => now(),
            'totp_last_counter' => is_int($timestamp) ? $timestamp : $this->google2fa->getTimestamp(),
            'totp_recovery_codes' => array_map(fn (string $plain) => Hash::make($plain), $recovery),
        ])->save();

        $this->logger->event('TotpService', 'totp.enrollment_confirmed', 'info', 'TOTP enrollment confirmed', [
            'user_id' => $user->id,
        ]);

        return ['recovery_codes' => $recovery];
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        $user->refresh();
        if (! $user->totpIsActive()) {
            throw new DomainException('Authenticator is not enabled.', 'TOTP_NOT_ENABLED', 422);
        }

        $this->assertNotRateLimited($user);
        $timestamp = $this->google2fa->verifyKeyNewer(
            (string) $user->totp_secret,
            $this->normalizeCode($code),
            $user->totp_last_counter,
            self::WINDOW,
        );
        if ($timestamp === false) {
            $this->hitRateLimit($user);
            throw new DomainException('Invalid authenticator code.', 'TOTP_INVALID', 422);
        }

        $this->clearRateLimit($user);
        $user->forceFill([
            'totp_last_counter' => is_int($timestamp) ? $timestamp : $this->google2fa->getTimestamp(),
        ])->save();

        $this->logger->event('TotpService', 'totp.verified', 'info', 'TOTP verified', [
            'user_id' => $user->id,
        ]);

        return true;
    }

    public function recover(User $user, #[\SensitiveParameter] string $recoveryCode): bool
    {
        $user->refresh();
        if (! $user->totpIsActive()) {
            throw new DomainException('Authenticator is not enabled.', 'TOTP_NOT_ENABLED', 422);
        }

        $this->assertNotRateLimited($user);
        $hashes = is_array($user->totp_recovery_codes) ? $user->totp_recovery_codes : [];
        $plain = strtoupper(preg_replace('/\s+/', '', $recoveryCode) ?? '');
        $matchedIndex = null;
        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && Hash::check($plain, $hash)) {
                $matchedIndex = $i;
                break;
            }
        }

        if ($matchedIndex === null) {
            $this->hitRateLimit($user);
            throw new DomainException('Invalid recovery code.', 'TOTP_RECOVERY_INVALID', 422);
        }

        unset($hashes[$matchedIndex]);
        $this->clearRateLimit($user);
        $user->forceFill([
            'totp_recovery_codes' => array_values($hashes),
        ])->save();

        $this->logger->event('TotpService', 'totp.recovery_used', 'info', 'TOTP recovery code used', [
            'user_id' => $user->id,
        ]);

        return true;
    }

    public function disable(User $user, #[\SensitiveParameter] string $code, bool $isRecovery = false): void
    {
        $user->refresh();
        if (! $user->totpIsActive() && ! (is_string($user->totp_pending_secret) && $user->totp_pending_secret !== '')) {
            throw new DomainException('Authenticator is not enabled.', 'TOTP_NOT_ENABLED', 422);
        }

        if ($isRecovery) {
            $this->recover($user, $code);
        } else {
            $this->verify($user, $code);
        }

        $user->forceFill([
            'totp_secret' => null,
            'totp_pending_secret' => null,
            'totp_confirmed_at' => null,
            'totp_last_counter' => null,
            'totp_recovery_codes' => null,
        ])->save();

        $this->logger->event('TotpService', 'totp.disabled', 'info', 'TOTP disabled', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Verify TOTP or consume a recovery code. Used at broker submit / mode change.
     */
    public function assertRecentVerification(User $user, #[\SensitiveParameter] ?string $totpCode, #[\SensitiveParameter] ?string $recoveryCode = null): void
    {
        $user->refresh();
        if (! $user->totpIsActive()) {
            throw new DomainException(
                'Authenticator must be enrolled before automated broker submission.',
                'TOTP_REQUIRED',
                403,
            );
        }

        $totp = is_string($totpCode) ? trim($totpCode) : '';
        $recovery = is_string($recoveryCode) ? trim($recoveryCode) : '';
        if ($totp === '' && $recovery === '') {
            throw new DomainException(
                'Authenticator code is required.',
                'TOTP_REQUIRED',
                403,
            );
        }

        if ($recovery !== '') {
            $this->recover($user, $recovery);

            return;
        }

        $this->verify($user, $totp);
    }

    public function currentOtpForTests(User $user): string
    {
        $secret = $user->totp_secret ?: $user->totp_pending_secret;
        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('No TOTP secret available.');
        }

        return $this->google2fa->getCurrentOtp($secret);
    }

    protected function assertNotRateLimited(User $user): void
    {
        $key = self::RATE_LIMIT_KEY_PREFIX.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_MAX)) {
            $seconds = RateLimiter::availableIn($key);
            throw new DomainException(
                'Too many authenticator attempts. Try again in '.$seconds.' seconds.',
                'TOTP_RATE_LIMITED',
                429,
            );
        }
    }

    protected function hitRateLimit(User $user): void
    {
        RateLimiter::hit(self::RATE_LIMIT_KEY_PREFIX.$user->id, self::RATE_LIMIT_DECAY_SECONDS);
    }

    protected function clearRateLimit(User $user): void
    {
        RateLimiter::clear(self::RATE_LIMIT_KEY_PREFIX.$user->id);
    }

    protected function normalizeCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }

    /**
     * @return list<string>
     */
    protected function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(Str::password(10, letters: true, numbers: true, symbols: false, spaces: false));
        }

        return $codes;
    }

    protected function qrSvg(string $otpauth): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);
        $writer = new Writer($renderer);

        return $writer->writeString($otpauth);
    }
}
