<?php

namespace App\Services\Notification;

use App\Models\NotificationChannelSetting;
use App\Models\NotificationEmailDestination;
use App\Models\User;
use App\Mail\NotificationEmailVerificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class NotificationChannelSettingsService
{
    public const EXTERNAL_CHANNELS = ['telegram', 'email', 'webhook'];

    public function all(User $user): array
    {
        $accountEmail = NotificationEmailDestination::query()->firstOrCreate(
            ['user_id' => $user->id, 'email' => $user->email],
            ['is_account_email' => true, 'verified_at' => $user->email_verified_at],
        );

        $settings = NotificationChannelSetting::query()->where('user_id', $user->id)->get()->keyBy('channel');

        return [
            ['channel' => 'in_app', 'enabled' => true, 'health_status' => 'healthy', 'can_disable' => false],
            ...array_map(fn (string $channel) => $this->present($settings->get($channel), $channel, $accountEmail), self::EXTERNAL_CHANNELS),
        ];
    }

    public function emailDestinations(User $user): array
    {
        $this->ensureAccountEmail($user);

        return NotificationEmailDestination::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_account_email')
            ->orderBy('email')
            ->get()
            ->map(fn (NotificationEmailDestination $destination) => $this->presentDestination($destination))
            ->all();
    }

    public function addEmailDestination(User $user, string $email): array
    {
        $this->ensureAccountEmail($user);
        $email = strtolower(trim($email));
        $existing = NotificationEmailDestination::query()->where('user_id', $user->id)->where('email', $email)->first();
        if ($existing?->is_account_email) {
            return $this->presentDestination($existing);
        }

        $token = bin2hex(random_bytes(32));
        $destination = NotificationEmailDestination::query()->updateOrCreate(
            ['user_id' => $user->id, 'email' => $email],
            [
                'is_account_email' => false,
                'verified_at' => null,
                'verification_token_hash' => hash('sha256', $token),
                'verification_expires_at' => now()->addDay(),
            ],
        );
        $url = URL::temporarySignedRoute('notification.email.verify', now()->addDay(), [
            'destination' => $destination->id,
            'token' => $token,
        ]);
        Mail::to($email)->send(new NotificationEmailVerificationMail($url));

        return $this->presentDestination($destination);
    }

    public function verifyEmailDestination(NotificationEmailDestination $destination, string $token): bool
    {
        if ($destination->is_account_email || ! $destination->verification_expires_at?->isFuture()) {
            return false;
        }
        if (! hash_equals((string) $destination->verification_token_hash, hash('sha256', $token))) {
            return false;
        }

        $destination->update([
            'verified_at' => now(),
            'verification_token_hash' => null,
            'verification_expires_at' => null,
        ]);
        return true;
    }

    public function removeEmailDestination(User $user, NotificationEmailDestination $destination): void
    {
        abort_unless($destination->user_id === $user->id, 404);
        if ($destination->is_account_email) {
            throw ValidationException::withMessages(['email' => ['The account email is always retained while Email is enabled.']]);
        }
        $destination->delete();
    }

    public function update(User $user, string $channel, array $configuration, ?bool $enabled): array
    {
        $this->assertExternalChannel($channel);

        return DB::transaction(function () use ($user, $channel, $configuration, $enabled) {
            $setting = NotificationChannelSetting::query()->firstOrNew([
                'user_id' => $user->id,
                'channel' => $channel,
            ]);
            $current = $setting->configuration ?? [];
            $next = array_merge($current, array_filter($configuration, fn ($value) => $value !== null));
            $generatedSecret = null;
            if ($channel === 'telegram' && (empty($next['bot_token']) || empty($next['chat_id']))) {
                throw ValidationException::withMessages([
                    'bot_token' => ['Telegram bot token and chat ID are required.'],
                ]);
            }
            if ($channel === 'webhook') {
                if (empty($next['url'])) {
                    throw ValidationException::withMessages(['url' => ['Webhook URL is required.']]);
                }
                $this->assertSafeWebhookUrl((string) ($next['url'] ?? ''));
            }
            if ($channel === 'webhook' && empty($next['signing_secret'])) {
                $generatedSecret = bin2hex(random_bytes(32));
                $next['signing_secret'] = $generatedSecret;
            }

            $materialChange = $setting->exists && $this->materialConfiguration($current) !== $this->materialConfiguration($next);
            if (! $setting->exists || $materialChange) {
                $setting->verified_at = null;
                $setting->health_status = 'unverified';
                $setting->enabled = false;
            }
            $setting->configuration = $next;

            if ($enabled === true && ! $setting->verified_at) {
                throw ValidationException::withMessages([
                    'enabled' => ['Test and verify this channel before enabling it.'],
                ]);
            }
            if ($enabled !== null) {
                $setting->enabled = $enabled;
            }
            $setting->save();
            if ($enabled === false) {
                app(NotificationChannelHealthService::class)->recovered($user, $channel);
            }

            $presented = $this->present($setting->fresh(), $channel);
            if ($generatedSecret !== null) {
                $presented['signing_secret_once'] = $generatedSecret;
            }

            return $presented;
        });
    }

    public function markVerified(User $user, string $channel): NotificationChannelSetting
    {
        $this->assertExternalChannel($channel);
        $setting = NotificationChannelSetting::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->firstOrFail();
        $setting->update([
            'verified_at' => now(),
            'last_tested_at' => now(),
            'last_test_status' => 'succeeded',
            'last_error_code' => null,
            'health_status' => 'healthy',
        ]);
        if ($channel === 'email') {
            NotificationEmailDestination::query()->updateOrCreate(
                ['user_id' => $user->id, 'email' => $user->email],
                ['is_account_email' => true, 'verified_at' => now()],
            );
        }

        return $setting->fresh();
    }

    private function present(?NotificationChannelSetting $setting, string $channel, ?NotificationEmailDestination $accountEmail = null): array
    {
        $config = $setting?->configuration ?? [];
        $data = [
            'channel' => $channel,
            'enabled' => (bool) $setting?->enabled,
            'health_status' => $setting?->health_status ?? 'unverified',
            'verified_at' => $setting?->verified_at?->toIso8601String(),
            'last_tested_at' => $setting?->last_tested_at?->toIso8601String(),
            'last_test_status' => $setting?->last_test_status,
            'can_disable' => true,
        ];

        if ($channel === 'telegram') {
            $data['bot_token_configured'] = ! empty($config['bot_token']);
            $data['chat_id'] = $config['chat_id'] ?? null;
        } elseif ($channel === 'webhook') {
            $data['url'] = $config['url'] ?? null;
            $data['signing_secret_configured'] = ! empty($config['signing_secret']);
        } elseif ($channel === 'email') {
            $data['account_email'] = $accountEmail?->email;
            $data['account_email_verified'] = (bool) $accountEmail?->verified_at;
        }

        return $data;
    }

    private function presentDestination(NotificationEmailDestination $destination): array
    {
        return [
            'id' => $destination->id,
            'email' => $destination->email,
            'is_account_email' => (bool) $destination->is_account_email,
            'verified_at' => $destination->verified_at?->toIso8601String(),
            'verification_pending' => ! $destination->is_account_email && ! $destination->verified_at,
        ];
    }

    private function ensureAccountEmail(User $user): NotificationEmailDestination
    {
        return NotificationEmailDestination::query()->firstOrCreate(
            ['user_id' => $user->id, 'email' => $user->email],
            ['is_account_email' => true, 'verified_at' => $user->email_verified_at],
        );
    }

    private function materialConfiguration(array $configuration): array
    {
        unset($configuration['signing_secret']);
        ksort($configuration);

        return $configuration;
    }

    private function assertExternalChannel(string $channel): void
    {
        if (! in_array($channel, self::EXTERNAL_CHANNELS, true)) {
            throw ValidationException::withMessages(['channel' => ['Unsupported or mandatory notification channel.']]);
        }
    }

    private function assertSafeWebhookUrl(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isLocalName = $host === 'localhost' || str_ends_with($host, '.localhost');
        $isUnsafeIp = filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        if ($host === '' || $isLocalName || $isUnsafeIp) {
            throw ValidationException::withMessages([
                'url' => ['Webhook destination must be a public HTTPS endpoint.'],
            ]);
        }
    }
}
