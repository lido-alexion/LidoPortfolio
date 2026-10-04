<?php

namespace App\Services\Operations;

use App\Jobs\CreateOrLinkGitHubIssueJob;
use App\Models\ApiFailureIncident;
use Illuminate\Support\Facades\DB;

class ApiFailureReporter
{
    public function __construct(private ApiFailurePolicy $policy, private ApiFailureFingerprint $fingerprints, private ApiFailureRedactor $redactor) {}

    public function observe(array $observation): ?ApiFailureIncident
    {
        try {
            $observation = $this->normalize($observation);
            if (! $this->policy->reportable($observation['http_status'], $observation['failure_class'], $observation)) return null;
            unset($observation['skip_reporting']);
            $fingerprint = $this->fingerprints->make($observation);
            $now = now();
            $incident = DB::transaction(function () use ($observation, $fingerprint, $now) {
                $incident = ApiFailureIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                if ($incident) {
                    $incident->update(['last_seen_at' => $now, 'occurrence_count' => $incident->occurrence_count + 1, 'safe_message' => $observation['safe_message'], 'trace_id' => $observation['trace_id']]);
                    return $incident->fresh();
                }
                return ApiFailureIncident::create([...$observation, 'fingerprint' => $fingerprint, 'first_seen_at' => $now, 'last_seen_at' => $now, 'sync_status' => 'pending']);
            });
            if (config('api_failure_reporting.enabled') && in_array(app()->environment(), config('api_failure_reporting.environments', []), true)) {
                $dispatch = function () use ($incident): void {
                    try {
                        $connection = config('api_failure_reporting.queue_connection', 'log-triage');
                        if (! in_array(config("queue.connections.$connection.driver"), ['database','redis','sqs','beanstalkd'], true)) throw new \RuntimeException('Async reporter queue required');
                        CreateOrLinkGitHubIssueJob::dispatch($incident->id)->onConnection($connection)
                            ->onQueue(config('api_failure_reporting.queue', 'log-triage'))->beforeCommit();
                    } catch (\Throwable) { LogTriageGuard::failure('api_queue_failed'); }
                };
                if (DB::transactionLevel() > 0) DB::afterCommit(fn () => LogTriageGuard::run($dispatch));
                else LogTriageGuard::run($dispatch);
            }
            return $incident;
        } catch (\Throwable $error) {
            LogTriageGuard::failure('api_observation_failed');
            return null;
        }
    }

    public function observeExternalResponse(string $component, string $method, string $endpoint, ?int $status, array $context = []): void
    {
        $this->observe([...$context, 'direction' => 'outbound_external', 'component' => $component, 'method' => $method, 'endpoint' => $endpoint, 'http_status' => $status]);
    }

    public function observeApiFailure(string $method, string $endpoint, ?int $status, ?string $message, array $context = []): void
    {
        $this->observe([...$context, 'direction' => 'frontend_internal', 'component' => 'laravel-api', 'method' => $method, 'endpoint' => $endpoint, 'http_status' => $status, 'safe_message' => $message]);
    }

    private function normalize(array $observation): array
    {
        $safe = $this->redactor->redact($observation['safe_message'] ?? $observation['message'] ?? null);
        return [
            'environment' => app()->environment(),
            'direction' => (string) ($observation['direction'] ?? 'unknown'),
            'component' => $this->redactor->message($observation['component'] ?? null),
            'method' => strtoupper((string) ($observation['method'] ?? '')) ?: null,
            'endpoint' => $this->fingerprints->normalizeEndpoint((string) ($observation['endpoint'] ?? '')),
            'http_status' => isset($observation['http_status']) ? (int) $observation['http_status'] : null,
            'failure_class' => $this->redactor->message($observation['failure_class'] ?? null),
            'error_category' => $this->redactor->message($observation['error_category'] ?? null),
            'safe_message' => is_string($safe) ? $safe : null,
            'trace_id' => $this->redactor->message($observation['trace_id'] ?? null),
            'skip_reporting' => (bool) ($observation['skip_reporting'] ?? false),
        ];
    }
}
