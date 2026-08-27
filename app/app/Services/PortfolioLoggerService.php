<?php

namespace App\Services;

use App\Support\RequestContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortfolioLoggerService
{
    public const LEVELS = ['debug', 'info', 'warning', 'error'];

    public const CHANNEL_APP = 'daily';

    public const CHANNEL_FRONTEND = 'frontend';

    public const CHANNEL_PROVIDER = 'provider';

    public const CHANNEL_SCHEDULER = 'scheduler';

    public function __construct(protected SettingsService $settings) {}

    public function shouldLog(string $level): bool
    {
        $configured = strtolower($this->settings->get('backend_log_level', 'info') ?? 'info');
        if (! in_array($configured, self::LEVELS, true)) {
            $configured = 'info';
        }

        return $this->levelRank($level) >= $this->levelRank($configured);
    }

    public function log(
        string $channel,
        string $category,
        string $level,
        string $message,
        array $context = [],
    ): void {
        if (! $this->shouldLog($level)) {
            return;
        }

        $normalizedLevel = $this->normalizeLevel($level);
        $payload = array_merge($this->baseContext($category), $this->sanitizeContext($context));

        Log::channel($channel)->log($normalizedLevel, $this->sanitizeMessage($message), $payload);
    }

    public function api(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_APP, 'API', $level, $message, $context);
    }

    public function scheduler(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_SCHEDULER, 'Scheduler', $level, $message, $context);
    }

    public function provider(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_PROVIDER, 'Provider', $level, $message, $context);
    }

    public function frontend(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_FRONTEND, 'UI', $level, $message, $context);
    }

    public function telegram(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_APP, 'Telegram', $level, $message, $context);
    }

    public function validation(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_APP, 'Validation', $level, $message, $context);
    }

    public function security(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_APP, 'Security', $level, $message, $context);
    }

    /**
     * TOS/platform structured event. Stable `event` + `engine` context fields.
     * Keep messages human-readable; put identifiers in $context, not the message string.
     */
    public function event(string $engine, string $event, string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_APP, $engine, $level, $message, array_merge([
            'event' => $event,
            'engine' => $engine,
        ], $context));
    }

    public function alertPolicy(string $level, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_APP, 'AlertPolicy', $level, $message, $context);
    }

    public function logFrontendPayload(array $payload): void
    {
        $level = $this->normalizeLevel((string) ($payload['level'] ?? 'error'));
        if (! $this->shouldLog($level)) {
            return;
        }

        $message = $this->sanitizeMessage((string) ($payload['message'] ?? 'Frontend log'));
        $context = $this->sanitizeContext([
            'category' => 'UI',
            'url' => $payload['url'] ?? null,
            'user_agent' => $payload['userAgent'] ?? null,
            'client_timestamp' => $payload['timestamp'] ?? null,
            'request_id' => $payload['requestId'] ?? RequestContext::getRequestId(),
            'extra' => $payload['extra'] ?? [],
        ]);

        Log::channel(self::CHANNEL_FRONTEND)->log($level, $message, $context);
    }

    protected function baseContext(string $category): array
    {
        return [
            'category' => $category,
            'request_id' => RequestContext::getRequestId(),
        ];
    }

    protected function levelRank(string $level): int
    {
        return match ($this->normalizeLevel($level)) {
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3,
            default => 1,
        };
    }

    protected function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return match ($level) {
            'warn' => 'warning',
            default => in_array($level, self::LEVELS, true) ? $level : 'info',
        };
    }

    protected function sanitizeMessage(string $message): string
    {
        $message = strip_tags($message);
        $message = preg_replace("/[\r\n]+/", ' ', $message) ?? $message;

        return Str::limit($message, 2000, '…');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function sanitizeContext(array $context): array
    {
        try {
            json_encode($context, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['context' => 'unserializable'];
        }

        return $this->redactSensitiveInStrings($this->redactSensitiveKeys($context));
    }

    /**
     * Redact secrets interpolated into string values (e.g. `token=abc`), not JSON keys.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function redactSensitiveInStrings(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $out[$key] = $this->redactSensitiveInStrings($item);
            } elseif (is_string($item) && $item !== '[REDACTED]') {
                $out[$key] = preg_replace(
                    '/(password|token|secret|authorization|cookie|api[_-]?key)\s*[:=]\s*\S+/i',
                    '$1=[REDACTED]',
                    $item,
                ) ?? $item;
            } else {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function redactSensitiveKeys(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            $out[$key] = is_array($item) ? $this->redactSensitiveKeys($item) : $item;
        }

        return $out;
    }

    protected function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach ([
            'password',
            'passwd',
            'secret',
            'token',
            'authorization',
            'cookie',
            'api_key',
            'apikey',
            'access_token',
            'refresh_token',
            'bot_token',
            'csrf',
            'xsrf',
            'totp',
            'totp_code',
            'otp',
            'recovery_code',
            'recovery_codes',
            'request_token',
            'checksum',
            'api_secret',
        ] as $needle) {
            if ($normalized === $needle || str_ends_with($normalized, '_'.$needle)) {
                return true;
            }
        }

        return false;
    }
}
