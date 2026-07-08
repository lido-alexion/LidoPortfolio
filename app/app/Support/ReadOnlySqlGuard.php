<?php

namespace App\Support;

class ReadOnlySqlGuard
{
    /**
     * Allow only single-statement read-only SQL (SELECT, SHOW, DESCRIBE, EXPLAIN).
     */
    public static function isAllowed(string $sql): bool
    {
        $sql = trim($sql);
        if ($sql === '') {
            return false;
        }

        // Reject multiple statements (allow one trailing semicolon).
        $normalized = rtrim($sql, " \t\n\r\0\x0B;");
        if (str_contains($normalized, ';')) {
            return false;
        }

        $upper = strtoupper(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        $allowed = false;
        foreach (['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'] as $prefix) {
            if (str_starts_with($upper, $prefix.' ') || $upper === $prefix) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            return false;
        }

        foreach (['INTO OUTFILE', 'INTO DUMPFILE', 'FOR UPDATE', 'LOCK IN SHARE MODE', 'LOAD_FILE'] as $forbidden) {
            if (str_contains($upper, $forbidden)) {
                return false;
            }
        }

        return true;
    }
}
