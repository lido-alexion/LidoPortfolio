<?php

namespace App\Services\Operations;

use Illuminate\Support\Str;

class ApiFailureRedactor
{
    private const SECRET_KEYS = ['authorization', 'cookie', 'set-cookie', 'token', 'password', 'secret', 'api_key', 'apikey', 'credential', 'private_key'];

    public function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) return '[redacted-depth]';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));
                $out[$key] = in_array($normalized, self::SECRET_KEYS, true) || Str::contains($normalized, ['password', 'token', 'secret', 'authorization', 'cookie'])
                    ? '[redacted]'
                    : $this->redact($child, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) return $this->redact((array) $value, $depth + 1);
        if (is_string($value)) {
            // Redact common inline credential forms before the value reaches
            // incident storage. Structured payloads are never accepted here.
            $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $value) ?? $value;
            $value = preg_replace('/\b(?:token|access_token|refresh_token|api[_-]?key|password|secret|authorization|cookie)\s*[:=]\s*[^\s,;&]+/i', '[credential-redacted]', $value) ?? $value;
            $value = preg_replace('/https?:\/\/[^\s]+/i', '[url-redacted]', $value) ?? $value;
            $value = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[email-redacted]', $value) ?? $value;
            $value = preg_replace('/\b(?:user|account|portfolio|customer)[ _-]?(?:id|#)\s*[:=]?\s*[A-Za-z0-9_-]+/i', '[identifier-redacted]', $value) ?? $value;
            return Str::limit($value, 1000, '…');
        }
        return is_scalar($value) || $value === null ? $value : '[redacted]';
    }

    public function message(?string $message, int $limit = 1000): ?string
    {
        $redacted = $this->redact($message);
        return is_string($redacted) ? Str::limit($redacted, $limit, '…') : null;
    }
}
