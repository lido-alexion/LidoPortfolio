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
        $json = json_encode($context);
        if ($json === false) {
            return ['context' => 'unserializable'];
        }

        $redacted = preg_replace(
            '/(password|token|secret|authorization|cookie|api[_-]?key)["\']?\s*[:=]\s*["\']?[^"\',\s}]+/i',
            '$1":"[REDACTED]"',
            $json,
        ) ?? $json;

        $decoded = json_decode($redacted, true);

        return is_array($decoded) ? $decoded : ['context' => Str::limit($redacted, 5000)];
    }
}
