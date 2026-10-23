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
                $browser = $incident->direction === 'browser_runtime';
                $marker = '<!-- '.($browser ? 'stox-browser-observation:' : 'stox-api-failure:').$incident->fingerprint.' -->';
                $title = $browser
                    ? '[Auto][Browser] StoX observed '.($incident->component ?: 'a browser runtime fault')
                    : '[Auto][API] '.($incident->component ?: 'StoX').' '.($incident->http_status ?: $incident->failure_class ?: 'failure');
                $result = $github->report($marker, $title, $this->body($incident, $marker), ['automated', $browser ? 'browser-observation' : 'api-failure', app()->environment()], $incident->last_seen_at);
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
        $browser = $incident->direction === 'browser_runtime';
        return implode("\n", [
            $browser
                ? 'Automated StoX browser runtime observation. This is an untrusted client signal, not a confirmed code bug; no client message, stack, URL, or account data is retained.'
                : 'Automated StoX API failure report.',
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
