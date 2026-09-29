<?php

namespace App\Telemetry;

use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use Throwable;

/** Keep SDK export failures observable without repeating their PHP stack traces. */
final class OtelDiagnosticLogWriter implements LogWriterInterface
{
    private static array $lastSeen = [];

    public function write($level, string $message, array $context): void
    {
        $exception = $context['exception'] ?? null;
        $detail = $exception instanceof Throwable ? $exception->getMessage() : '';
        $key = $message.'|'.$detail;
        $now = microtime(true);
        $last = self::$lastSeen[$key] ?? null;
        if ($last !== null && $now - $last < 60) {
            return;
        }
        self::$lastSeen[$key] = $now;

        error_log(sprintf('OpenTelemetry: [%s] %s%s', $level, $message, $detail !== '' ? ': '.$detail : ''));
    }
}
