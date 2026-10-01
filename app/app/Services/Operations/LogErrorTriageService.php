<?php

namespace App\Services\Operations;

use App\Jobs\TriageLogErrorJob;
use App\Models\LogErrorTriage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogErrorTriageService
{
    private const CLASSIFICATIONS = [
        'code_bug', 'external_dependency', 'configuration_or_environment',
        'expected_operational_condition', 'data_quality_or_input',
        'security_or_abuse_signal', 'uncertain',
    ];

    public function observe(\Throwable $exception, array $context = []): void
    {
        try {
            if (! config('log_error_triage.enabled') || ! in_array(app()->environment(), config('log_error_triage.environments', []), true)) return;
            $envelope = $this->sanitize($exception, $context);
            if ($this->excluded($envelope)) return;
            $fingerprint = hash('sha256', implode('|', [$envelope['component'], $envelope['exception_class'], $envelope['safe_message']]));
            $now = now();
            $triage = DB::transaction(function () use ($fingerprint, $envelope, $now) {
                $existing = LogErrorTriage::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                if ($existing && $existing->last_seen_at?->gt($now->copy()->subMinutes((int) config('log_error_triage.debounce_minutes', 5)))) {
                    $existing->increment('occurrence_count');
                    $existing->update(['last_seen_at' => $now]);
                    return null;
                }
                return LogErrorTriage::query()->updateOrCreate(
                    ['fingerprint' => $fingerprint],
                    [...$envelope, 'first_seen_at' => $existing?->first_seen_at ?? $now, 'last_seen_at' => $now, 'status' => 'pending'],
                );
            });
            if ($triage) TriageLogErrorJob::dispatch($triage->id)->afterCommit();
        } catch (\Throwable $failure) {
            Log::warning('Log error triage failed open', ['error' => $failure->getMessage(), 'skip_log_error_triage' => true]);
        }
    }

    public function applyClassification(LogErrorTriage $triage, array $result): void
    {
        $classification = (string) ($result['classification'] ?? 'uncertain');
        if (! in_array($classification, self::CLASSIFICATIONS, true)) $classification = 'uncertain';
        $triage->update([
            'classification' => $classification,
            'confidence' => (float) ($result['confidence'] ?? 0),
            'evidence' => is_string($result['evidence'] ?? null) ? substr($result['evidence'], 0, 2000) : null,
            'status' => 'triaged',
            'triaged_at' => now(),
        ]);
    }

    private function sanitize(\Throwable $exception, array $context): array
    {
        $message = preg_replace('/([A-Za-z0-9_\-]*(?:token|secret|password|authorization|cookie)[A-Za-z0-9_\-]*\s*[:=]\s*)[^\s,;]+/i', '$1[REDACTED]', $exception->getMessage()) ?? '';
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $message) ?? $message;
        $safeContext = [];
        foreach (['component', 'route', 'method', 'trace_id', 'job', 'build_sha'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) $safeContext[$key] = substr((string) $context[$key], 0, 200);
        }
        return [
            'environment' => app()->environment(), 'severity' => 'error',
            'component' => $safeContext['component'] ?? 'laravel',
            'exception_class' => substr($exception::class, 0, 200),
            'safe_message' => substr($message, 0, 2000), 'safe_context' => $safeContext,
        ];
    }

    private function excluded(array $envelope): bool
    {
        return str_contains(strtolower($envelope['exception_class'].' '.$envelope['safe_message']), 'logerrortriage')
            || str_contains(strtolower($envelope['safe_message']), 'github reporting');
    }
}
