<?php

namespace App\Services\Operations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/** Shared OPS-002/003 reconciliation, recurrence, global admission and circuit. */
class GitHubIssueReporter
{
    public function __construct(private GitHubIssueClient $client) {}

    public function report(string $marker, string $title, string $body, array $labels, \DateTimeInterface $seen, bool $allowCreate = true): array
    {
        return LogTriageGuard::run(function () use ($marker, $title, $body, $labels, $seen, $allowCreate) {
            if (! config('api_failure_reporting.enabled') || ! in_array(app()->environment(), config('api_failure_reporting.environments', []), true)) return ['status' => 'disabled'];
            try {
                // Shared database cache lock serializes admission/reconciliation. Writes
                // commit BEFORE the POST, so a crash retains creation_unknown.
                return Cache::store('database')->lock('stox-github-report-global', 180)->get(function () use ($marker, $title, $body, $labels, $seen, $allowCreate) {
                    $state = DB::table('stox_github_reporter_state')->where('id', 1)->first();
                    if (! $state) return ['status' => 'failed', 'reason' => 'reporter_state_missing'];
                    if ($state->circuit_until && Carbon::parse($state->circuit_until)->isFuture()) return ['status' => 'circuit_open'];
                    DB::table('stox_github_issue_bindings')->insertOrIgnore(['marker' => $marker, 'state' => 'unknown']);
                    $binding = DB::table('stox_github_issue_bindings')->where('marker', $marker)->first();
                    $bindings = DB::table('stox_github_issue_bindings')->where('id', $binding->id);
                    $bindings->update(['last_seen_at' => $seen, 'last_checked_at' => now()]);
                    try {
                        $issue = $this->client->searchOpen($marker);
                        $prior = null;
                        if (! $issue) {
                            // Read a known issue directly as GitHub search indexing may lag.
                            $prior = $binding->issue_number ? $this->client->get((int) $binding->issue_number) : $this->client->searchAny($marker);
                            if ($prior && ($prior['state'] ?? null) === 'open') $issue = $prior;
                        }
                        if (! $issue && $binding->state === 'creation_unknown') {
                            // An ambiguous POST must never be blindly repeated after search lag.
                            return ['status' => 'reconciliation_required', 'reason' => 'creation_outcome_unknown'];
                        }
                        if (! $issue && $prior) {
                            $closed = isset($prior['closed_at']) ? Carbon::parse($prior['closed_at']) : ($binding->closed_at ? Carbon::parse($binding->closed_at) : Carbon::parse($binding->last_seen_at ?? $seen));
                            $bindings->update(['issue_number' => $prior['number'], 'issue_url' => $this->safeUrl($prior), 'state' => 'closed', 'closed_at' => $closed, 'generation' => max(1, $binding->generation)]);
                            if (Carbon::instance($seen)->lt($closed->copy()->addHours(config('api_failure_reporting.recurrence_cooldown_hours', 24)))) {
                                $this->healthy();
                                return ['status' => 'cooldown', 'number' => $prior['number'], 'url' => $this->safeUrl($prior), 'generation' => max(1, $binding->generation)];
                            }
                        }
                        if (! $issue && ! $allowCreate) {
                            $this->healthy();
                            return ['status' => $prior ? 'closed' : 'unknown'];
                        }
                        $generation = $prior ? max(1, (int) $binding->generation) : (int) $binding->generation;
                        $history = json_decode($binding->history ?: '[]', true);
                        if ($issue && (int) $binding->issue_number !== (int) $issue['number']) {
                            // Recover generation history even when a successful POST lost its response.
                            $generation = max(1, (int) $binding->generation + 1);
                            $history[] = ['generation' => $generation, 'number' => $issue['number'], 'reconciled_at' => now()->toIso8601String(), 'prior_number' => $binding->issue_number];
                        }
                        if (! $issue) {
                            $attempts = array_values(array_filter(json_decode($state->creation_attempts ?: '[]', true), fn ($time) => Carbon::parse($time)->gt(now()->subHour())));
                            if (count($attempts) >= config('api_failure_reporting.max_new_per_hour', 10)) return ['status' => 'rate_limited'];
                            // Count attempts in a rolling hour; ambiguous POSTs consume admission.
                            $attempts[] = now()->toIso8601String();
                            DB::table('stox_github_reporter_state')->where('id', 1)->update(['window_started_at' => Carbon::parse($attempts[0]), 'created_count' => count($attempts), 'creation_attempts' => json_encode($attempts, JSON_THROW_ON_ERROR)]);
                            $bindings->update(['state' => 'creation_unknown']);
                            if ($prior) $body .= "\n\nRecurrence of #".$prior['number'].'. The prior issue remains closed.';
                            $issue = $this->client->create($title, $body, $labels);
                            $generation++;
                            $history[] = ['generation' => $generation, 'number' => $issue['number'], 'created_at' => now()->toIso8601String(), 'prior_number' => $prior['number'] ?? null];
                        }
                        $bindings->update(['issue_number' => $issue['number'], 'issue_url' => $this->safeUrl($issue), 'state' => 'open', 'closed_at' => null, 'generation' => max(1, $generation), 'history' => json_encode($history, JSON_THROW_ON_ERROR)]);
                        $this->healthy();
                        return ['status' => 'linked', 'number' => $issue['number'], 'url' => $this->safeUrl($issue), 'generation' => max(1, $generation)];
                    } catch (\Throwable) {
                        $failures = (int) $state->failures + 1;
                        DB::table('stox_github_reporter_state')->where('id', 1)->update(['failures' => $failures, 'circuit_until' => $failures >= config('api_failure_reporting.circuit_failure_threshold', 3) ? now()->addSeconds(config('api_failure_reporting.circuit_seconds', 300)) : null]);
                        LogTriageGuard::failure('github_failed');
                        return ['status' => 'failed', 'reason' => 'github_failed'];
                    }
                }) ?: ['status' => 'busy'];
            } catch (\Throwable) {
                LogTriageGuard::failure('reporter_persistence_failed');
                return ['status' => 'failed', 'reason' => 'reporter_persistence_failed'];
            }
        });
    }

    private function healthy(): void
    {
        DB::table('stox_github_reporter_state')->where('id', 1)->update(['failures' => 0, 'circuit_until' => null]);
    }

    private function safeUrl(array $issue): string
    {
        return 'https://github.com/'.config('api_failure_reporting.repository').'/issues/'.(int) $issue['number'];
    }
}
