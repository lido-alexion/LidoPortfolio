<?php

namespace App\Services\VpsHealth;

use App\Mail\NotificationDeliveryMail;
use App\Models\NotificationChannelSetting;
use App\Models\User;
use App\Services\Notification\LegacyTelegramChannelMigrator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class VpsHealthNotificationService
{
    public function __construct(private LegacyTelegramChannelMigrator $legacyTelegram) {}

    /** @return array{success:bool,email_recipients:int,telegram_recipients:int,cache_used:bool,failures:int} */
    public function send(string $title, string $message, bool $urgent = false): array
    {
        [$targets, $cacheUsed] = $this->targets();
        $failures = 0;
        $emailsSent = 0;
        $telegramsSent = 0;

        if (! in_array(config('mail.default'), ['log', 'array'], true)) {
            foreach ($targets['emails'] as $email) {
                try {
                    Mail::to($email)->send(new NotificationDeliveryMail($title, $message));
                    $emailsSent++;
                } catch (Throwable) {
                    $failures++;
                }
            }
        }

        foreach ($urgent ? $targets['telegram'] : [] as $destination) {
            try {
                $response = Http::timeout(5)->post(
                    'https://api.telegram.org/bot'.$destination['bot_token'].'/sendMessage',
                    ['chat_id' => $destination['chat_id'], 'text' => mb_substr($message, 0, 3500)],
                );
                if ($response->successful()) {
                    $telegramsSent++;
                } else {
                    $failures++;
                }
            } catch (Throwable) {
                // Never log the URL or exception: Telegram URLs contain the bot token.
                $failures++;
            }
        }

        $success = $emailsSent + $telegramsSent > 0 && $failures === 0;
        return [
            'success' => $success,
            'email_recipients' => $emailsSent,
            'telegram_recipients' => $telegramsSent,
            'cache_used' => $cacheUsed,
            'failures' => $failures,
        ];
    }

    /** @return array{0:array{emails:list<string>,telegram:list<array{bot_token:string,chat_id:string}>},1:bool} */
    private function targets(): array
    {
        try {
            $targets = $this->readDatabaseTargets();
            $this->writeCache($targets);
            return [$targets, false];
        } catch (Throwable) {
            Log::warning('VPS health notifier could not refresh StoX admin targets; trying encrypted cache.');
            $cached = $this->readCache();
            if ($cached !== null) return [$cached, true];
            return [['emails' => [], 'telegram' => []], true];
        }
    }

    /** @return array{emails:list<string>,telegram:list<array{bot_token:string,chat_id:string}>} */
    private function readDatabaseTargets(): array
    {
        $admins = User::query()->where('is_admin', true)->orderBy('id')->get(['id', 'email']);
        foreach ($admins as $admin) {
            $this->legacyTelegram->migrateIfUnambiguous($admin);
        }

        $emails = $admins->pluck('email')
            ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()->values()->all();
        $adminIds = $admins->pluck('id');
        $telegram = NotificationChannelSetting::query()
            ->whereIn('user_id', $adminIds)
            ->where('channel', 'telegram')
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->get()
            ->map(function (NotificationChannelSetting $setting): ?array {
                $configuration = $setting->configuration ?? [];
                $token = trim((string) ($configuration['bot_token'] ?? ''));
                $chatId = trim((string) ($configuration['chat_id'] ?? ''));
                return $token !== '' && $chatId !== '' ? ['bot_token' => $token, 'chat_id' => $chatId] : null;
            })
            ->filter()
            ->unique(fn (array $destination) => hash('sha256', $destination['bot_token']."\0".$destination['chat_id']))
            ->values()->all();

        return ['emails' => $emails, 'telegram' => $telegram];
    }

    /** @param array{emails:list<string>,telegram:list<array{bot_token:string,chat_id:string}>} $targets */
    private function writeCache(array $targets): void
    {
        $directory = (string) config('vps_health.state_dir', '/var/lib/vps-health');
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) return;
        $cache = [
            'created_at' => now()->toIso8601String(),
            'targets' => $targets,
        ];
        $path = $this->cachePath();
        $temporary = $path.'.'.getmypid().'.tmp';
        try {
            $encrypted = Crypt::encryptString(json_encode($cache, JSON_THROW_ON_ERROR));
            if (file_put_contents($temporary, $encrypted, LOCK_EX) === false || ! @chmod($temporary, 0600)) {
                @unlink($temporary);
                return;
            }
            if (! @rename($temporary, $path)) {
                @unlink($temporary);
                return;
            }
            @chmod($path, 0600);
        } catch (Throwable) {
            @unlink($temporary);
        }
    }

    /** @return array{emails:list<string>,telegram:list<array{bot_token:string,chat_id:string}>}|null */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (! is_file($path) || is_link($path) || filesize($path) > 65536) return null;
        try {
            $decoded = json_decode(Crypt::decryptString((string) file_get_contents($path)), true, 16, JSON_THROW_ON_ERROR);
            $createdAt = isset($decoded['created_at']) ? strtotime((string) $decoded['created_at']) : false;
            $ttl = (int) config('vps_health.recipient_cache_ttl_seconds', 604800);
            if (! is_array($decoded['targets'] ?? null) || $createdAt === false || time() - $createdAt > $ttl) return null;
            if (! is_array($decoded['targets']['emails'] ?? null) || ! is_array($decoded['targets']['telegram'] ?? null)) return null;
            return $decoded['targets'];
        } catch (Throwable) {
            return null;
        }
    }

    private function cachePath(): string
    {
        return rtrim((string) config('vps_health.state_dir', '/var/lib/vps-health'), '/').'/notification-targets.enc';
    }
}
