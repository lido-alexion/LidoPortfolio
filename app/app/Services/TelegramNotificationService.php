<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramNotificationService
{
    public function __construct(
        protected SettingsService $settings,
        protected SystemLogService $logger,
    ) {}

    public function sendMessage(string $message): bool
    {
        if ($this->settings->get('notifications_enabled', 'true') !== 'true') {
            return false;
        }

        $token = $this->settings->get('telegram_bot_token');
        $chatId = $this->settings->get('telegram_chat_id');

        if (! $token || ! $chatId) {
            $this->logger->log('telegram', 'Telegram credentials not configured', [], 'warning');

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
        return $this->sendMessage('Portfolio sync failure: '.$details);
    }
}
