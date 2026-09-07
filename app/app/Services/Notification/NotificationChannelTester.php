<?php

namespace App\Services\Notification;

use App\Mail\NotificationChannelTestMail;
use App\Models\NotificationChannelSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationChannelTester
{
    public function __construct(private NotificationChannelSettingsService $settings) {}

    public function test(User $user, string $channel): array
    {
        $setting = NotificationChannelSetting::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->firstOrFail();

        try {
            $successful = match ($channel) {
                'telegram' => $this->telegram($setting->configuration ?? []),
                'email' => $this->email($user),
                'webhook' => $this->webhook($setting->configuration ?? [], $user),
                default => false,
            };
        } catch (Throwable) {
            $successful = false;
        }

        if ($successful) {
            $this->settings->markVerified($user, $channel);
        } else {
            $setting->update([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_error_code' => 'DELIVERY_TEST_FAILED',
                'health_status' => 'unverified',
            ]);
        }

        return [
            'successful' => $successful,
            'channel' => $channel,
            'message' => $successful
                ? 'Channel verified successfully.'
                : 'Channel verification failed. Check the destination and service configuration.',
        ];
    }

    private function telegram(array $configuration): bool
    {
        $token = trim((string) ($configuration['bot_token'] ?? ''));
        $chatId = trim((string) ($configuration['chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            return false;
        }

        return Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => 'StoX notification channel verification test.',
        ])->successful();
    }

    private function email(User $user): bool
    {
        Mail::to($user->email)->send(new NotificationChannelTestMail);

        return true;
    }

    private function webhook(array $configuration, User $user): bool
    {
        $url = (string) ($configuration['url'] ?? '');
        $secret = (string) ($configuration['signing_secret'] ?? '');
        if ($url === '' || $secret === '') {
            return false;
        }

        $payload = [
            'schema_version' => '1.0',
            'type' => 'notification.channel_test',
            'sent_at' => now()->toIso8601String(),
            'account_id' => $user->id,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $secret);

        return Http::timeout(15)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-StoX-Signature' => 'sha256='.$signature,
                'X-StoX-Schema-Version' => '1.0',
            ])
            ->withBody($body, 'application/json')
            ->post($url)
            ->successful();
    }
}
