<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\User;

class TelegramNotificationService
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected SystemLogService $logger,
    ) {}

    public function sendMessageForProfile(PortfolioProfile $profile, string $message): bool
    {
        if ($this->profileSettings->get($profile, 'notifications_enabled', 'true') !== 'true') {
            return false;
        }

        $token = $this->profileSettings->get($profile, 'telegram_bot_token');
        $chatId = $this->profileSettings->get($profile, 'telegram_chat_id');

        if (! $token || ! $chatId) {
            $this->logger->log('telegram', 'Telegram credentials not configured for profile', [
                'profile_id' => $profile->id,
            ], 'warning');

            return false;
        }

        return $this->sendMessageWithCredentials($message, $token, $chatId);
    }

    public function sendMessageWithCredentials(string $message, string $token, string $chatId): bool
    {
        $token = trim($token);
        $chatId = trim($chatId);

        if ($token === '' || $chatId === '') {
            $this->logger->log('telegram', 'Telegram credentials not configured', [], 'warning');

            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            if (! $response->successful()) {
                $this->logger->log('telegram', 'Telegram API failure', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->log('telegram', 'Telegram request exception: '.$e->getMessage());

            return false;
        }
    }

    public function sendSyncFailureAlert(string $details): bool
    {
        $message = 'Portfolio sync failure: '.$details;
        $sent = false;

        foreach (PortfolioProfile::query()->orderBy('id')->get() as $profile) {
            if ($this->sendMessageForProfile($profile, $message)) {
                $sent = true;
            }
        }

        return $sent;
    }

    /**
     * @return array{sent: bool, recipients: int}
     */
    public function sendAdminOperationalAlert(string $message): array
    {
        $sentKeys = [];

        foreach (User::query()->where('is_admin', true)->orderBy('id')->get() as $admin) {
            foreach ($admin->portfolios()->orderByDesc('is_default')->orderBy('id')->get() as $profile) {
                if ($this->profileSettings->get($profile, 'notifications_enabled', 'true') !== 'true') {
                    continue;
                }

                $token = trim((string) $this->profileSettings->get($profile, 'telegram_bot_token'));
                $chatId = trim((string) $this->profileSettings->get($profile, 'telegram_chat_id'));
                if ($token === '' || $chatId === '') {
                    continue;
                }

                $dedupeKey = $token.'|'.$chatId;
                if (isset($sentKeys[$dedupeKey])) {
                    continue;
                }

                if ($this->sendMessageWithCredentials($message, $token, $chatId)) {
                    $sentKeys[$dedupeKey] = true;
                }
            }
        }

        return [
            'sent' => $sentKeys !== [],
            'recipients' => count($sentKeys),
        ];
    }

    public function countAdminTelegramRecipients(): int
    {
        $keys = [];

        foreach (User::query()->where('is_admin', true)->get() as $admin) {
            foreach ($admin->portfolios as $profile) {
                $token = trim((string) $this->profileSettings->get($profile, 'telegram_bot_token'));
                $chatId = trim((string) $this->profileSettings->get($profile, 'telegram_chat_id'));
                if ($token !== '' && $chatId !== '') {
                    $keys[$token.'|'.$chatId] = true;
                }
            }
        }

        return count($keys);
    }
}
