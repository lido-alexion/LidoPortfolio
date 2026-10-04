<?php

namespace App\Services\Operations;

/** No free-form message/context crosses this boundary, even after regex redaction. */
final class LogErrorSanitizer
{
    public function envelope(string $level, string $message, ?\Throwable $exception, array $frames): array
    {
        $safeFrames = [];
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';
            if (! is_string($file) || ! str_starts_with($file, app_path().DIRECTORY_SEPARATOR)) continue;
            $relative = substr($file, strlen(base_path()) + 1);
            if (! preg_match('~^app/[A-Za-z0-9_/]+\.php$~D', $relative)) continue;
            if (preg_match('~(?:Services/Operations/|Jobs/(?:TriageLogError|CreateOrLinkGitHubIssue)|Providers/AppServiceProvider)~', $relative)) continue;
            $safeFrames[] = $relative.':'.max(1, (int) ($frame['line'] ?? 1));
            if (count($safeFrames) === 5) break;
        }
        $safeFrames = array_values(array_unique($safeFrames));
        $category = 'Application error';
        foreach (['null' => 'Null access', 'undefined' => 'Undefined value', 'typeerror' => 'Type mismatch', 'sqlstate' => 'Database error', 'invariant' => 'Invariant violation', 'timeout' => 'Operation timeout', 'connection refused' => 'Connection refused'] as $needle => $label) {
            if (str_contains(strtolower(($exception ? $exception::class : '').' '.$message), $needle)) { $category = $label; break; }
        }
        $class = $exception ? $exception::class : null;
        if ($class && ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,190}$/D', $class)) $class = 'Throwable';
        $context = ['frames' => $safeFrames];
        // Route metadata comes from the registered route, never a URL or caller context.
        $route = request()?->route();
        if ($route instanceof \Illuminate\Routing\Route) {
            $context['route'] = substr($route->uri(), 0, 200);
            $context['method'] = in_array(request()->method(), ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true) ? request()->method() : 'OTHER';
        }
        $sha = (string) config('log_error_triage.build_sha', '');
        if ($sha === '' && is_file(base_path('bootstrap/build-info.json'))) {
            $build = json_decode(file_get_contents(base_path('bootstrap/build-info.json')), true);
            $sha = is_string($build['commit_sha'] ?? null) ? $build['commit_sha'] : '';
        }
        $context['build_sha'] = preg_match('/^[a-f0-9]{7,40}$/D', $sha) ? $sha : 'unknown';
        $context['security_sensitive'] = (bool) preg_match('/security|abuse|unauthori[sz]ed|authentication|credential|password|secret|token|cookie|authorization/i', $message.' '.($class ?? ''));
        return [
            'environment' => app()->environment(), 'severity' => $level,
            'component' => isset($safeFrames[0]) ? explode(':', $safeFrames[0])[0] : null,
            'exception_class' => $class, 'safe_message' => $category,
            'safe_context' => $context,
        ];
    }

    public function evidence(array $envelope): array
    {
        return array_values(array_filter([
            $envelope['exception_class'] ? 'Exception: '.$envelope['exception_class'] : null,
            'Diagnostic: '.$envelope['safe_message'],
            ...array_map(fn ($frame) => 'Application frame: '.$frame, $envelope['safe_context']['frames'] ?? []),
        ]));
    }

    public function signature(array $envelope, string $message): string
    {
        // A keyed, local-only discriminator preserves distinct errors at the same site.
        // Remove known volatile values, but never collapse unrelated prose by similarity.
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b|\b\d+\b/i', '{value}', $message);
        $normalized = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '{email}', $normalized);
        $normalized = preg_replace('/\b(?:token|password|secret|cookie|authorization|user_id|account_id)\s*[:=]\s*[^\s,;]+/i', '{private}', $normalized);
        return hash_hmac('sha256', json_encode([$envelope['environment'], $envelope['exception_class'], $envelope['safe_context'], $normalized], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
