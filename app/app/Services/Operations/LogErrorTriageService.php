<?php

namespace App\Services\Operations;

use App\Jobs\TriageLogErrorJob;
use App\Models\LogErrorTriage;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;

class LogErrorTriageService
{
    public function __construct(private LogErrorSanitizer $sanitizer) {}

    public function enabled(): bool
    {
        return config('log_error_triage.enabled') && in_array(app()->environment(), config('log_error_triage.environments', []), true);
    }

    public function observe(\Throwable $exception, array $context = []): void
    {
        $this->observeLog(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception, ...$context]));
    }

    public function observeLog(MessageLogged $event): void
    {
        if (LogTriageGuard::active()) return;
        LogTriageGuard::run(function () use ($event) {
            try {
                if (! $this->enabled() || ! in_array($event->level, ['error','critical','alert','emergency'], true)) return;
                $context = $event->context;
                if (($context['skip_log_error_triage'] ?? false) || ($context['skip_api_failure_reporting'] ?? false)
                    || ($context['capability_id'] ?? $context['capability'] ?? null) === 'ops.log_error_triage') return;
                if (in_array($context['job'] ?? null, [TriageLogErrorJob::class, \App\Jobs\CreateOrLinkGitHubIssueJob::class], true)) return;
                $exception = ($context['exception'] ?? null) instanceof \Throwable ? $context['exception'] : null;
                $frames = $exception ? [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()] : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);
                foreach ($frames as $frame) {
                    if ($exception && (str_starts_with((string) ($frame['class'] ?? ''), __NAMESPACE__.'\\') || in_array($frame['class'] ?? '', [TriageLogErrorJob::class, \App\Jobs\CreateOrLinkGitHubIssueJob::class], true))) return;
                }
                if ($exception && in_array($exception::class, config('log_error_triage.ignored_exceptions', []), true)) return;
                $message = is_string($event->message) ? $event->message : '';
                if (in_array($message, config('log_error_triage.ignored_messages', []), true)) return;
                $route = request()?->route();
                if ($route instanceof \Illuminate\Routing\Route && in_array($route->uri(), config('log_error_triage.ignored_routes', []), true)) return;
                $envelope = $this->sanitizer->envelope($event->level, $message, $exception, $frames);
                if (! $envelope['exception_class'] && ! $envelope['component']) return;
                if (($context['security_sensitive'] ?? false) || in_array(strtolower((string) ($context['category'] ?? '')), ['security','abuse'], true)) $envelope['safe_context']['security_sensitive'] = true;
                $signature = $this->sanitizer->signature($envelope, $message);
                $id = DB::transaction(function () use ($signature, $envelope) {
                    // Atomic upsert takes a write lock immediately; duplicate INSERT IGNORE
                    // shared-lock upgrades can deadlock and lose concurrent occurrences.
                    DB::table('stox_log_error_triages')->upsert([
                        ...$envelope, 'safe_context' => json_encode($envelope['safe_context'], JSON_THROW_ON_ERROR),
                        'signature' => $signature, 'first_seen_at' => now(), 'last_seen_at' => now(),
                        'occurrence_count' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ], ['signature'], ['occurrence_count' => DB::raw('occurrence_count + 1'), 'last_seen_at' => now(), 'updated_at' => now()]);
                    $row = LogErrorTriage::where('signature', $signature)->lockForUpdate()->firstOrFail();
                    $row->last_seen_at = now();
                    $cached = $row->triaged_at && $row->triaged_at->gt(now()->subSeconds(config('log_error_triage.decision_ttl_seconds')));
                    $inFlight = $row->lease_until?->isFuture();
                    $scheduled = $row->next_attempt_at && $row->next_attempt_at->gt(now()->subMinutes(10));
                    $dispatch = ! $inFlight && ! $scheduled && (! $cached || $this->issueEligible($row));
                    if ($dispatch) {
                        $row->next_attempt_at = now()->addSeconds(config('log_error_triage.debounce_seconds'));
                        if (! $cached) $row->status = 'pending';
                    }
                    $row->save();
                    return $dispatch ? $row->id : null;
                }, 3);
                if ($id) $this->dispatch($id);
            } catch (\Throwable) { LogTriageGuard::failure('observation_failed'); }
        });
    }

    public function dispatch(int $id): void
    {
        try {
            // Catch deferred dispatch errors inside the callback, not only registration.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => LogTriageGuard::run(fn () => $this->enqueue($id)));
            } else {
                $this->enqueue($id);
            }
        } catch (\Throwable) { $this->fail($id, 'queue_failed'); }
    }

    private function enqueue(int $id): void
    {
        try {
            $connection = config('log_error_triage.queue_connection');
            if (! in_array(config("queue.connections.$connection.driver"), ['database','redis','sqs','beanstalkd'], true)) throw new \RuntimeException('Async queue required');
            TriageLogErrorJob::dispatch($id)->onConnection($connection)->onQueue(config('log_error_triage.queue'))
                ->delay(now()->addSeconds(config('log_error_triage.debounce_seconds')))->beforeCommit();
        } catch (\Throwable) {
            $this->fail($id, 'queue_failed');
        }
    }

    public function fail(int $id, string $reason): void
    {
        try { LogErrorTriage::whereKey($id)->update(['status' => 'failed', 'failure_reason' => $reason, 'lease_until' => null, 'lease_token' => null, 'next_attempt_at' => null]); }
        catch (\Throwable) { /* Original operation still wins. */ }
        LogTriageGuard::failure($reason, $id);
    }

    public function input(LogErrorTriage $row): array
    {
        return [
            'severity' => $row->severity, 'component' => $row->component, 'exception_class' => $row->exception_class,
            'message' => $row->safe_message, 'context' => $row->safe_context,
            'occurrence_count' => $row->occurrence_count,
            'evidence_candidates' => $this->sanitizer->evidence($row->toArray()),
        ];
    }

    public function schema(): array
    {
        return json_decode(file_get_contents(config_path('ai-schemas/ops.log_error_triage.v1.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function applyClassification(LogErrorTriage $row, array $result): void
    {
        $schema = $this->schema();
        if (array_diff($schema['required'], array_keys($result)) || array_diff(array_keys($result), $schema['required'])) throw new \UnexpectedValueException('structured_output_invalid');
        foreach (['summary' => 500, 'safe_issue_title' => 160] as $key => $length) {
            if (! is_string($result[$key]) || mb_strlen($result[$key]) > $length) throw new \UnexpectedValueException('structured_output_invalid');
        }
        if (! is_string($result['classification']) || ! in_array($result['classification'], $schema['properties']['classification']['enum'], true)
            || ! in_array($result['actionability'], $schema['properties']['actionability']['enum'], true)
            || ! in_array($result['bug_kind'], $schema['properties']['bug_kind']['enum'], true)
            || ! is_bool($result['security_sensitive'])
            || (! is_float($result['confidence']) && ! is_int($result['confidence']))
            || ! is_finite((float) $result['confidence']) || $result['confidence'] < 0 || $result['confidence'] > 1
            || ! is_array($result['evidence']) || ! array_is_list($result['evidence']) || count($result['evidence']) > 8
            || ($result['suspected_component'] !== null && (! is_string($result['suspected_component']) || strlen($result['suspected_component']) > 200))) throw new \UnexpectedValueException('structured_output_invalid');
        $candidates = $this->sanitizer->evidence($row->toArray());
        foreach ($result['evidence'] as $item) {
            if (! is_string($item) || ! in_array($item, $candidates, true)) throw new \UnexpectedValueException('ungrounded_evidence');
        }
        if ($result['suspected_component'] !== null && $result['suspected_component'] !== $row->component) throw new \UnexpectedValueException('unstable_component');
        $security = $result['security_sensitive'] || ($row->safe_context['security_sensitive'] ?? true);
        // Model prose is never persisted. Use the validated extractive evidence and code identity.
        $row->fill([
            'classification' => $security ? 'security_or_abuse_signal' : $result['classification'],
            // Never round a just-below-threshold score upward in the DECIMAL(5,4) column.
            'confidence' => floor((float) $result['confidence'] * 10000) / 10000, 'actionability' => $result['actionability'],
            'bug_kind' => $result['bug_kind'], 'summary' => $row->safe_message.' in '.($row->component ?: $row->exception_class),
            'evidence' => json_encode(array_values(array_unique($result['evidence'])), JSON_THROW_ON_ERROR),
            'status' => 'classified', 'triaged_at' => now(), 'failure_reason' => null,
        ]);
        $identity = [$row->environment, $row->component, $row->exception_class, $row->safe_context['frames'][0] ?? null, $row->safe_context['route'] ?? null, $result['bug_kind']];
        $row->fingerprint = $row->component && $result['bug_kind'] !== 'unknown' ? hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)) : null;
        $row->save();
    }

    public function confidenceThreshold(): float
    {
        $value = config('log_error_triage.confidence_threshold', .85);
        return is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 1 ? (float) $value : .85;
    }

    public function issueEligible(LogErrorTriage $row): bool
    {
        return $row->classification === 'code_bug' && $row->confidence >= $this->confidenceThreshold()
            && $row->actionability === 'actionable' && collect(json_decode($row->evidence ?: '[]', true) ?: [])->contains(fn ($item) => is_string($item) && str_starts_with($item, 'Application frame: '))
            && $row->fingerprint && ! ($row->safe_context['security_sensitive'] ?? true);
    }
}
