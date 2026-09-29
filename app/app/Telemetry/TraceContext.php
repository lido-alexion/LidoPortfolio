<?php

namespace App\Telemetry;

use OpenTelemetry\API\Trace\Span;

final class TraceContext
{
    private static ?self $active = null;

    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $sampled = true,
    ) {}

    public static function fromRequest(?string $traceparent): self
    {
        if (is_string($traceparent) && preg_match('/^00-([a-f0-9]{32})-([a-f0-9]{16})-([0-9a-f]{2})$/i', trim($traceparent), $m)) {
            return new self(strtolower($m[1]), strtolower($m[2]), hexdec($m[3]) === 1);
        }

        return self::freshRoot();
    }

    public static function freshRoot(): self
    {
        return new self(self::randomHex(32), self::randomHex(16), true);
    }

    public static function activate(self $context): void
    {
        self::$active = $context;
    }

    public static function clear(): void
    {
        self::$active = null;
    }

    public static function active(): ?self
    {
        return self::$active;
    }

    public static function effective(bool $officialSdkActive = false): ?self
    {
        if ($officialSdkActive && class_exists(Span::class)) {
            try {
                $spanContext = Span::getCurrent()->getContext();
                if ($spanContext->isValid()) {
                    return new self(
                        strtolower($spanContext->getTraceId()),
                        strtolower($spanContext->getSpanId()),
                        $spanContext->isSampled(),
                    );
                }
            } catch (\Throwable) {
                // Context lookup is best effort and must never affect the app.
            }
        }

        return self::$active;
    }

    public static function officialSdkActive(): bool
    {
        return (bool) config('lido_telemetry.enabled', false)
            && (bool) config('lido_telemetry.official_sdk_enabled', false)
            && extension_loaded('opentelemetry');
    }

    public function childSpan(): self
    {
        return new self($this->traceId, self::randomHex(16), $this->sampled);
    }

    public function traceparent(): string
    {
        $flags = $this->sampled ? '01' : '00';

        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $flags);
    }

    private static function randomHex(int $length): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}
