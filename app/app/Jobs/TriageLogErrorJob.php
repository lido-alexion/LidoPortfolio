<?php

namespace App\Jobs;

use App\Models\LogErrorTriage;
use App\Services\AI\AiRuntimeClient;
use App\Services\Operations\GitHubIssueClient;
use App\Services\Operations\LogErrorTriageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TriageLogErrorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $triageId) {}

    public function handle(AiRuntimeClient $ai, LogErrorTriageService $service, GitHubIssueClient $github): void
    {
        $triage = LogErrorTriage::find($this->triageId);
        if (! $triage) return;
        if (! config('api_failure_reporting.enabled')) return;
        try {
            $result = $ai->infer('ops.log_error_triage', ['severity' => $triage->severity, 'component' => $triage->component, 'exception_class' => $triage->exception_class, 'message' => $triage->safe_message, 'context' => $triage->safe_context], ['request_id' => 'log-triage-'.$triage->id]);
            $structured = (array) ($result['structured'] ?? $result['output'] ?? []);
            $service->applyClassification($triage, $structured);
            $triage->refresh();
            if ($triage->classification !== 'code_bug' || ($triage->confidence ?? 0) < (float) config('log_error_triage.confidence_threshold', 0.85)) return;
            $marker = '<!-- stox-log-bug:'.$triage->fingerprint.' -->';
            $issue = $github->searchOpen($marker) ?: $github->create('[Auto][Bug] '.($triage->component ?: 'StoX'), implode("\n", ['Automated high-confidence code-bug triage.', '', '- Component: `'.$triage->component.'`', '- Exception: `'.$triage->exception_class.'`', '- Confidence: '.$triage->confidence, '- Evidence: '.($triage->evidence ?: 'Unavailable'), '', $marker]), ['automated', 'log-triage', app()->environment()]);
            $triage->update(['github_issue_number' => $issue['number'] ?? null, 'github_issue_url' => $issue['html_url'] ?? null, 'status' => 'reported']);
        } catch (\Throwable $error) {
            $triage->update(['status' => 'failed']);
            Log::warning('LLM log triage failed open', ['triage_id' => $triage->id, 'error' => $error->getMessage(), 'skip_log_error_triage' => true]);
        }
    }
}
