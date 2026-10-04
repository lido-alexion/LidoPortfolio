<?php

namespace App\Console\Commands;

use App\Models\LogErrorTriage;
use App\Services\Operations\GitHubIssueReporter;
use App\Services\Operations\LogErrorTriageService;
use App\Services\Operations\LogTriageGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainLogErrorTriages extends Command
{
    protected $signature = 'ops:maintain-log-triages';
    protected $description = 'Recover bounded pending triage work and prune expired sanitized records';

    public function handle(LogErrorTriageService $service): int
    {
        return LogTriageGuard::run(function () use ($service) {
            try {
                if ($service->enabled()) {
                    LogErrorTriage::where(function ($query) {
                        $query->whereIn('status', ['pending','failed'])->orWhereIn('report_status', ['failed','rate_limited','circuit_open','busy','reconciliation_required']);
                    })->where('last_seen_at', '>=', now()->subDay())
                        ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
                        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<', now()->subMinutes(10)))
                        ->orderBy('updated_at')->limit(50)->get()->each(function ($row) use ($service) {
                            $row->update(['next_attempt_at' => now()->addSeconds(config('log_error_triage.debounce_seconds'))]);
                            $service->dispatch($row->id);
                        });
                }
                if ($service->enabled()) {
                    DB::table('stox_github_issue_bindings')->where('marker', 'like', '<!-- stox-log-bug:%')->where('state', 'open')
                        ->where('last_checked_at', '<', now()->subDay())->orderBy('last_checked_at')->limit(10)->get()->each(function ($binding) {
                            app(GitHubIssueReporter::class)->report($binding->marker, '', '', [], \Carbon\Carbon::parse($binding->last_seen_at), false);
                        });
                }
                $cutoff = now()->subDays(config('log_error_triage.retention_days', 90));
                // Unknown/open bindings are conservatively retained. Reconciliation refreshes closure.
                DB::transaction(function () use ($cutoff) {
                    // Select under the same row locks as deletion so a fresh occurrence
                    // cannot race the retention snapshot and be discarded.
                    $ids = LogErrorTriage::where('last_seen_at', '<', $cutoff)
                        ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
                        ->where(function ($query) use ($cutoff) {
                            $query->whereNull('github_issue_number')->orWhereIn('fingerprint', function ($q) use ($cutoff) {
                                $q->selectRaw("SUBSTR(marker, 19, 64)")->from('stox_github_issue_bindings')->where('state', 'closed')->where('closed_at', '<', $cutoff);
                            });
                        })->limit(500)->lockForUpdate()->pluck('id');
                    DB::table('stox_log_triage_decisions')->whereIn('triage_id', $ids)->delete();
                    LogErrorTriage::whereIn('id', $ids)->delete();
                });
                return self::SUCCESS;
            } catch (\Throwable) { LogTriageGuard::failure('maintenance_failed'); return self::FAILURE; }
        });
    }
}
