<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class TelegramNotificationService
{
    public function __construct(
        protected UserSettingsService $userSettings,
        protected SystemLogService $logger,
    ) {}

    public function sendMessageForUser(User $user, string $message): bool
    {
        if ($this->userSettings->get($user, 'notifications_enabled', 'true') !== 'true') {
            return false;
        }

        $token = $this->userSettings->get($user, 'telegram_bot_token');
        $chatId = $this->userSettings->get($user, 'telegram_chat_id');

        if (! $token || ! $chatId) {
            $this->logger->log('telegram', 'Telegram credentials not configured for user', [
                'user_id' => $user->id,
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
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
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

        foreach (User::query()->orderBy('id')->get() as $user) {
            if ($this->sendMessageForUser($user, $message)) {
                $sent = true;
            }
        }

        return $sent;
    }
}
