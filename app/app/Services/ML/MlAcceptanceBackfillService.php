<?php

namespace App\Services\ML;

use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Jobs\MlAcceptanceJob;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Models\V8\MlUniverseSnapshotBoundary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MlAcceptanceBackfillService
{
    public function preview(array $ids, int $actor): MlUniverseSnapshotBackfillRun
    {
        app(MlAcceptanceRuntime::class)->assertQueue();
        validator(['sources' => $ids], ['sources' => 'required|array|min:1|max:5000', 'sources.*' => 'required|uuid|distinct'])->validate();
        $sources = MlAcceptanceSource::query()->whereIn('id', $ids)->get()->sortBy(fn ($s) => $s->manifest['date']);
        if ($sources->count() !== count($ids) || $sources->contains(fn ($s) => $s->status !== 'sealed')) {
            $this->fail('Every source must be sealed.');
        }
        $dates = $sources->map(fn ($s) => $s->manifest['date'])->values()->all();
        if (count($dates) !== count(array_unique($dates))) {
            $this->fail('Select one source for each date.');
        }
        $run = MlUniverseSnapshotBackfillRun::query()->create([
            'universe_key' => MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE,
            'source' => 'admin_sealed_nse', 'requested_dates' => $dates, 'processed_dates' => [], 'failed_dates' => [],
            'retry_counts' => [], 'status' => 'queued',
            'acceptance' => ['mode' => 'preview', 'sources' => $sources->pluck('id')->values()->all(), 'cursor' => 0, 'results' => [], 'actor_id' => $actor,
                'history' => app(MlAcceptanceRuntime::class)->history([], 'preview', $actor)],
        ]);
        MlAcceptanceJob::dispatch('backfill', (string) $run->id);

        return $run;
    }

    /** A terminal status alone does not prove that every governed date finished. */
    public function isComplete(MlUniverseSnapshotBackfillRun $run): bool
    {
        $state = $run->acceptance ?? [];
        $dates = $run->requested_dates ?? [];
        if (! is_array($state) || ! is_array($dates) || ! array_is_list($dates)) {
            return false;
        }
        $sources = $state['sources'] ?? [];
        $results = $state['results'] ?? [];
        if ($run->status !== 'completed' || $dates === []
            || ! in_array($state['mode'] ?? null, ['preview', 'apply'], true)
            || ($state['cursor'] ?? null) !== count($dates)
            || $run->processed_dates !== $dates || ($run->failed_dates ?? []) !== []
            || ! is_array($sources) || count($sources) !== count($dates)
            || ! is_array($results) || count($results) !== count($dates)) {
            return false;
        }
        $seenDates = $seenSources = [];
        foreach ($dates as $index => $date) {
            $source = $sources[$index] ?? null;
            if (! is_string($date) || ! is_string($source) || $source === ''
                || isset($seenDates[$date]) || isset($seenSources[$source])) {
                return false;
            }
            $seenDates[$date] = $seenSources[$source] = true;
            $result = $results[$date] ?? [];
            $digest = $result['snapshot_sha256'] ?? null;
            if (! is_string($digest) || ! preg_match('/\A[a-f0-9]{64}\z/', $digest)
                || ($result['diagnostics']['source_id'] ?? null) !== $source) {
                return false;
            }
        }

        return true;
    }

    public function action(MlUniverseSnapshotBackfillRun $run, string $action, int $actor): MlUniverseSnapshotBackfillRun
    {
        if ($action !== 'cancel') {
            app(MlAcceptanceRuntime::class)->assertQueue();
        }

        return Cache::lock('ml-backfill-'.$run->id, MlAcceptanceRuntime::LOCK_SECONDS)->block(5, function () use ($run, $action, $actor) {
            $run->refresh();
            $state = $run->acceptance;
            if (! is_array($state)) {
                $this->fail('This is not an acceptance backfill.');
            }
            if ($action === 'apply') {
                if ($state['mode'] !== 'preview' || ! $this->isComplete($run)) {
                    $this->fail('Successful preview is required.');
                }
                $state['mode'] = 'apply';
                $state['cursor'] = 0;
                $run->processed_dates = [];
            } elseif ($action === 'resume') {
                if (! in_array($run->status, ['failed', 'queued', 'running'], true)) {
                    $this->fail('This operation cannot resume.');
                }
                $run->failed_dates = [];
                $run->retry_counts = [];
            } elseif ($action === 'cancel') {
                if (in_array($run->status, ['completed', 'cancelled'], true)) {
                    $this->fail('This operation has finished.');
                }
            } else {
                $this->fail('Unsupported action.');
            }
            $state['history'] = app(MlAcceptanceRuntime::class)->history($state['history'], $action, $actor);
            $run->forceFill(['acceptance' => $state, 'status' => $action === 'cancel' ? 'cancelled' : 'queued', 'completed_at' => null, 'last_error' => null])->save();
            if ($action !== 'cancel') {
                MlAcceptanceJob::dispatch('backfill', (string) $run->id);
            }

            return $run;
        });
    }

    /** One source/date per invocation; cursor and materialization commit atomically. */
    public function step(int $id): void
    {
        Cache::lock('ml-backfill-'.$id, MlAcceptanceRuntime::LOCK_SECONDS)->block(5, function () use ($id) {
            $run = MlUniverseSnapshotBackfillRun::query()->findOrFail($id);
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $state = $run->acceptance;
            $cursor = $state['cursor'];
            $date = $run->requested_dates[$cursor];
            try {
                app(MlHistoricalUniverseMembershipService::class)->withWriteLock(function () use ($run, &$state, $cursor, $date) {
                    DB::transaction(function () use ($run, &$state, $cursor, $date) {
                        $source = MlAcceptanceSource::query()->findOrFail($state['sources'][$cursor]);
                        $snapshot = app(MlAcceptanceSourceService::class)->snapshot($source);
                        $boundary = MlUniverseSnapshotBoundary::query()->where('universe_key', $run->universe_key)->whereDate('effective_from', $date)->first();
                        if ($boundary && (($boundary->quality_diagnostics['source_sha256'] ?? null) !== $source->manifest['sha256'] || ($boundary->quality_diagnostics['membership_sha256'] ?? null) !== $snapshot['diagnostics']['membership_sha256'])) {
                            $this->fail('Existing snapshot has conflicting or unproven provenance.');
                        }
                        $digest = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
                        if ($state['mode'] === 'apply') {
                            if (($state['results'][$date]['snapshot_sha256'] ?? null) !== $digest) {
                                $this->fail('Source mapping changed after preview; create a new preview.');
                            }
                            app(MlHistoricalUniverseMembershipService::class)->backfillHistoricalSnapshots([$snapshot], 'admin_sealed_nse', $run->id, $run->universe_key);
                            // The nested backfill updates this shared run for its one-date batch.
                            // Reload its originals so our authoritative governed state below is
                            // persisted even when queued/null matched the pre-batch originals.
                            $run->refresh();
                        }
                        $diagnostics = $snapshot['diagnostics'];
                        unset($diagnostics['unmapped_identifiers']);
                        $state['results'][$date] = ['snapshot_sha256' => $digest, 'diagnostics' => $diagnostics, 'existing' => $boundary !== null];
                        $state['cursor'] = $cursor + 1;
                        $done = $state['cursor'] === count($state['sources']);
                        $run->forceFill(['acceptance' => $state, 'processed_dates' => array_slice($run->requested_dates, 0, $state['cursor']), 'status' => $done ? 'completed' : 'queued', 'failed_dates' => [], 'last_error' => null, 'started_at' => $run->started_at ?? now(), 'completed_at' => $done ? now() : null])->save();
                    });
                });
                if ($run->status === 'queued') {
                    MlAcceptanceJob::dispatch('backfill', (string) $id);
                }
            } catch (\Throwable $e) {
                $run->refresh();
                if ($e instanceof MlHistoricalUniverseProviderException && $e->diagnostics !== []) {
                    $state = $run->acceptance;
                    $state['results'][$date] = ['status' => 'blocked', 'diagnostics' => app(MlAcceptanceReportService::class)->safe($e->diagnostics)];
                    $run->acceptance = $state;
                }
                $counts = $run->retry_counts ?? [];
                $counts[$date] = ($counts[$date] ?? 0) + 1;
                $failed = $counts[$date] >= 3;
                $run->forceFill(['status' => $failed ? 'failed' : 'queued', 'retry_counts' => $counts, 'failed_dates' => [$date], 'last_error' => 'Source validation, mapping or provenance gate failed.'])->save();
                if (! $failed) {
                    MlAcceptanceJob::dispatch('backfill', (string) $id)->delay(now()->addSeconds(30 * $counts[$date]));
                }
            }
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['backfill' => [$message]]);
    }
}
