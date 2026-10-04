<?php

namespace App\Services\Operations;

/** Process-local, nestable guard. Always unwound, including persistence/log failures. */
final class LogTriageGuard
{
    private static int $depth = 0;

    public static function active(): bool { return self::$depth > 0; }

    public static function run(callable $operation): mixed
    {
        self::$depth++;
        try { return $operation(); } finally { self::$depth--; }
    }

    public static function failure(string $reason, ?int $id = null): void
    {
        try {
            \Illuminate\Support\Facades\Log::warning('Operational reporter failed open', [
                'reason' => $reason, 'triage_id' => $id,
                'skip_log_error_triage' => true, 'skip_api_failure_reporting' => true,
            ]);
        } catch (\Throwable) { /* Logging must not replace the original outcome. */ }
    }
}
