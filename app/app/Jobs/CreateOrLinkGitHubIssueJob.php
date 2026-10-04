<?php

namespace App\Jobs;

use App\Models\ApiFailureIncident;
use App\Services\Operations\GitHubIssueReporter;
use App\Services\Operations\LogTriageGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateOrLinkGitHubIssueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $incidentId) {}

    public function backoff(): array { return [60, 300]; }

    public function handle(GitHubIssueReporter $github): void
    {
        LogTriageGuard::run(function () use ($github) {
            try {
                $incident = ApiFailureIncident::find($this->incidentId);
                if (! $incident) return;
                $marker = '<!-- stox-api-failure:'.$incident->fingerprint.' -->';
                $result = $github->report($marker, '[Auto][API] '.($incident->component ?: 'StoX').' '.($incident->http_status ?: $incident->failure_class ?: 'failure'), $this->body($incident, $marker), ['automated', 'api-failure', app()->environment()], $incident->last_seen_at);
                $incident->update([
                    'sync_status' => $result['status'], 'sync_error' => $result['reason'] ?? null,
                    'github_issue_number' => $result['number'] ?? $incident->github_issue_number,
                    'github_issue_url' => $result['url'] ?? $incident->github_issue_url,
                    'github_issue_state' => isset($result['number']) ? ($result['status'] === 'cooldown' ? 'closed' : 'open') : $incident->github_issue_state,
                    'last_github_checked_at' => now(),
                    'last_reported_at' => isset($result['number']) ? now() : $incident->last_reported_at,
                ]);
            } catch (\Throwable) { LogTriageGuard::failure('api_reporter_failed'); }
        });
    }

    private function body(ApiFailureIncident $incident, string $marker): string
    {
        return implode("\n", [
            'Automated StoX API failure report.',
            '',
            '- Environment: `'.$incident->environment.'`',
            '- Direction: `'.$incident->direction.'`',
            '- Component: `'.($incident->component ?: 'unknown').'`',
            '- Method: `'.($incident->method ?: 'unknown').'`',
            '- Endpoint: `'.($incident->endpoint ?: 'unknown').'`',
            '- Status/failure: `'.($incident->http_status ?: $incident->failure_class ?: 'unknown').'`',
            '- First seen: '.$incident->first_seen_at?->toIso8601String(),
            '- Last seen: '.$incident->last_seen_at?->toIso8601String(),
            '- Occurrences: '.$incident->occurrence_count,
            '- Error category: `'.($incident->error_category ?: 'unknown').'`',
            '- Safe summary: '.($incident->safe_message ?: 'Unavailable'),
            $incident->trace_id ? '- Trace/request ID: `'.$incident->trace_id.'`' : null,
            '',
            $marker,
        ]);
    }
}
