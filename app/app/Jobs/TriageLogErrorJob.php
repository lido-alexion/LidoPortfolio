<?php

namespace App\Jobs;

use App\Models\LogErrorTriage;
use App\Services\AI\AiRuntimeClient;
use App\Services\Operations\GitHubIssueReporter;
use App\Services\Operations\LogErrorTriageService;
use App\Services\Operations\LogTriageGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TriageLogErrorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $triageId) {}

    public function handle(AiRuntimeClient $ai, LogErrorTriageService $service, GitHubIssueReporter $github): void
    {
        LogTriageGuard::run(function () use ($ai, $service, $github) {
            try {
                if (! $service->enabled()) return;
                $token = (string) Str::uuid();
                $row = DB::transaction(function () use ($token) {
                    $row = LogErrorTriage::whereKey($this->triageId)->lockForUpdate()->first();
                    if (! $row || $row->lease_until?->isFuture() || $row->next_attempt_at?->isFuture()) return null;
                    $row->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(5)]);
                    return $row;
                });
                if (! $row) return;
                $cached = $row->triaged_at && $row->triaged_at->gt(now()->subSeconds(config('log_error_triage.decision_ttl_seconds')));
                if (! $cached) {
                    $requestId = (string) Str::uuid();
                    $row->update(['inference_request_id' => $requestId]);
                    $result = $ai->infer('ops.log_error_triage', $service->input($row), ['request_id' => $requestId, 'trace_id' => '', 'output_schema' => $service->schema()]);
                    if (($result['status'] ?? null) !== 'success') {
                        $code = $result['error_code'] ?? '';
                        $reason = in_array($code, ['budget_exhausted','provider_timeout','provider_unavailable','configuration_invalid','concurrency_exhausted','structured_output_invalid'], true) ? 'ai_'.$code : 'ai_failed';
                        $service->fail($row->id, $reason);
                        return;
                    }
                    if (! is_array($result['structured'] ?? null)) throw new \UnexpectedValueException('structured_output_invalid');
                    $version = $result['prompt']['version'] ?? null;
                    $path = collect($result['routing_trace'] ?? [])->firstWhere('state', 'selected')['path_id'] ?? null;
                    if (! is_int($version) || $version < 1 || ! is_string($path) || ! preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D', $path)) throw new \UnexpectedValueException('inference_audit_missing');
                    DB::transaction(function () use ($service, $row, $result, $version, $path, $requestId) {
                        $service->applyClassification($row, $result['structured']);
                        $row->update(['prompt_version' => $version, 'provider_path' => $path]);
                        DB::table('stox_log_triage_decisions')->insert([
                        'triage_id' => $row->id, 'request_id' => $requestId,
                        'decision' => json_encode($row->only(['classification','confidence','actionability','bug_kind','summary','evidence','fingerprint','prompt_version','provider_path','occurrence_count']), JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        ]);
                    });
                }
                $row->refresh(); // Include occurrences arriving while inference was in flight.
                if ($service->issueEligible($row)) {
                    $marker = '<!-- stox-log-bug:'.$row->fingerprint.' -->';
                    $body = implode("\n", [
                        'Generated from AI-assisted StoX error triage. Evidence is sanitized and bounded.',
                        '- Environment: '.$row->environment, '- First seen: '.$row->first_seen_at->toIso8601String(),
                        '- Last seen: '.$row->last_seen_at->toIso8601String(), '- Occurrences: '.$row->occurrence_count,
                        '- Deploy: '.($row->safe_context['build_sha'] ?? 'unknown'), '- Exception: '.$row->exception_class,
                        '- Component: '.$row->component, '- Route: '.($row->safe_context['route'] ?? 'unavailable'),
                        '- Classification: '.$row->classification, '- Confidence: '.$row->confidence,
                        '- Summary: '.$row->summary, '- Evidence: '.$row->evidence, '', $marker,
                    ]);
                    $outcome = $github->report($marker, '[Auto][Bug] '.substr($row->summary, 0, 140), $body, ['automated','code-bug','production','ai-triaged'], $row->last_seen_at);
                    $row->update(['report_status' => $outcome['status'], 'failure_reason' => $outcome['reason'] ?? null,
                        'github_issue_number' => $outcome['number'] ?? $row->github_issue_number,
                        'github_issue_url' => $outcome['url'] ?? $row->github_issue_url,
                        'generation' => $outcome['generation'] ?? $row->generation, 'last_report_attempt_at' => now()]);
                }
                LogErrorTriage::whereKey($row->id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null]);
            } catch (\Throwable $error) {
                $service->fail($this->triageId, $error instanceof \UnexpectedValueException ? 'invalid_classification' : 'triage_failed');
            }
        });
    }

    public function failed(?\Throwable $error): void
    {
        LogTriageGuard::run(fn () => app(LogErrorTriageService::class)->fail($this->triageId, 'worker_failed'));
    }
}
