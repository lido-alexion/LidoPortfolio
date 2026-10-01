<?php

namespace App\Jobs;

use App\Models\ApiFailureIncident;
use App\Services\Operations\GitHubIssueClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateOrLinkGitHubIssueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $incidentId) {}

    public function backoff(): array { return [60, 300]; }

    public function handle(GitHubIssueClient $github): void
    {
        $incident = ApiFailureIncident::find($this->incidentId);
        if (! $incident || ! config('api_failure_reporting.enabled')) return;
        if (! in_array(app()->environment(), config('api_failure_reporting.environments', []), true)) return;

        $marker = '<!-- stox-api-failure:'.$incident->fingerprint.' -->';
        try {
            $incident->update(['sync_status' => 'syncing', 'last_github_checked_at' => now(), 'sync_error' => null]);
            $existing = $github->searchOpen($marker);
            $issue = $existing ?: $github->create(
                '[Auto][API] '.($incident->component ?: 'StoX').' '.($incident->http_status ?: $incident->failure_class ?: 'failure'),
                $this->body($incident, $marker),
                ['automated', 'api-failure', app()->environment()],
            );
            $incident->update([
                'github_issue_number' => $issue['number'] ?? null,
                'github_issue_url' => $issue['html_url'] ?? null,
                'github_issue_state' => $issue['state'] ?? 'open',
                'sync_status' => 'linked',
                'last_reported_at' => now(),
            ]);
        } catch (\Throwable $error) {
            $incident->update(['sync_status' => 'failed', 'sync_error' => substr($error->getMessage(), 0, 1000)]);
            Log::warning('API failure GitHub reporting failed', ['incident_id' => $incident->id, 'error' => $error->getMessage(), 'skip_api_failure_reporting' => true]);
        }
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
