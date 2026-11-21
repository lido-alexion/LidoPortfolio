<?php

namespace App\Console\Commands;

use App\Jobs\CreateOrLinkGitHubIssueJob;
use App\Models\ApiFailureIncident;
use Illuminate\Console\Command;

/** Manually enqueue a small, reviewed set of stale API incidents. */
class ReconcileApiFailureIncidents extends Command
{
    protected $signature = 'ops:reconcile-api-failure-incidents
        {--execute : Dispatch eligible incidents (default is a read-only dry run)}
        {--limit=10 : Maximum rows to inspect; capped at 10}
        {--older-than-days=7 : Require last sighting to be at least this many days old}';

    protected $description = 'Dry-run or manually enqueue a bounded set of never-checked API incidents';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $age = filter_var($this->option('older-than-days'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $age === false || $age < 1) {
            $this->error('Limit and age must be positive integers.');
            return self::INVALID;
        }
        $limit = min(10, $limit);

        if ($this->option('execute')) {
            if (! config('api_failure_reporting.enabled') || ! in_array(app()->environment(), config('api_failure_reporting.environments', []), true)) {
                $this->error('API issue reporting is disabled for this environment.');
                return self::FAILURE;
            }
            $connection = (string) config('api_failure_reporting.queue_connection', 'log-triage');
            if (! in_array(config("queue.connections.$connection.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                $this->error('Configured reporter queue is not a supported durable queue.');
                return self::FAILURE;
            }
        }

        $cutoff = now()->subDays($age);
        $eligible = ApiFailureIncident::query()
            ->where('sync_status', 'pending')
            ->whereNull('last_github_checked_at')
            ->whereNull('github_issue_number')
            ->whereNull('github_issue_url')
            ->whereNull('github_issue_state')
            ->where('last_seen_at', '<=', $cutoff)
            // A binding of any state is evidence requiring normal reporter
            // reconciliation; never bypass closed or ambiguous generations.
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('stox_github_issue_bindings')
                    ->whereRaw("marker = CONCAT('<!-- ', CASE WHEN portfolio_api_failure_incidents.direction = 'browser_runtime' THEN 'stox-browser-observation:' ELSE 'stox-api-failure:' END, portfolio_api_failure_incidents.fingerprint, ' -->')");
            })
            ->orderBy('last_seen_at')->orderBy('id')->limit($limit)->get();

        $ids = $eligible->modelKeys();
        $mode = $this->option('execute') ? 'eligible' : 'dry-run eligible';
        $this->line(sprintf('%s incidents: %d (limit %d; last seen on/before %s).', $mode, count($ids), $limit, $cutoff->toDateString()));
        if (! $this->option('execute') || $ids === []) return self::SUCCESS;

        $connection = (string) config('api_failure_reporting.queue_connection', 'log-triage');
        $queue = (string) config('api_failure_reporting.queue', 'log-triage');
        $dispatched = 0;
        foreach ($ids as $id) {
            CreateOrLinkGitHubIssueJob::dispatch((int) $id)->onConnection($connection)->onQueue($queue)->beforeCommit();
            $dispatched++;
        }
        $this->info(sprintf('Dispatched %d incident jobs to the configured reporter queue.', $dispatched));
        return self::SUCCESS;
    }
}
