<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\BootstrapCoverageGap;
use App\Models\V7\BootstrapQualityWarning;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only coverage projection plus explicit, audited reconciliation. */
class BootstrapCoverageOperationsService
{
    public function __construct(private FundamentalMetricCatalog $catalog) {}

    public function summary(): array
    {
        $eligible = Stock::query()->effectivelyActive()->where(fn ($q) => $q->where('is_benchmark', false)->orWhereNull('is_benchmark'));
        $denominator = (clone $eligible)->count();
        $facts = DB::table('stox_fundamental_facts as f')
            ->joinSub((clone $eligible)->select('id'), 'eligible', 'eligible.id', '=', 'f.stock_id')
            ->where('f.is_current', true)->whereIn('f.cadence', ['quarterly', 'annual'])
            ->whereIn('f.fact_key', $this->catalog->primaryFactKeys())
            ->select('f.fact_key', 'f.cadence')->selectRaw('COUNT(DISTINCT f.stock_id) as covered_stocks')
            ->groupBy('f.fact_key', 'f.cadence')->get()->keyBy(fn ($row) => $row->fact_key.':'.$row->cadence);

        $coverage = [];
        foreach ($this->catalog->primaryFactKeys() as $metric) {
            foreach (['quarterly', 'annual'] as $cadence) {
                $covered = (int) ($facts[$metric.':'.$cadence]->covered_stocks ?? 0);
                $coverage[] = [
                    'metric' => $metric, 'cadence' => $cadence, 'covered_stocks' => $covered,
                    'eligible_stocks' => $denominator,
                    'coverage_percent' => $denominator > 0 ? round(100 * $covered / $denominator, 2) : null,
                    'applicability' => 'unknown',
                ];
            }
        }

        // Actual period coverage only; no expected historical period grid is inferred.
        $periodCoverage = DB::table('stox_fundamental_facts as f')
            ->joinSub((clone $eligible)->select('id'), 'eligible', 'eligible.id', '=', 'f.stock_id')
            ->where('f.is_current', true)->whereIn('f.cadence', ['quarterly', 'annual'])
            ->whereIn('f.fact_key', $this->catalog->primaryFactKeys())
            ->select('f.fact_key as metric', 'f.cadence', 'f.period_end')
            ->selectRaw('COUNT(DISTINCT f.stock_id) as covered_stocks')->groupBy('f.fact_key', 'f.cadence', 'f.period_end')
            ->orderByDesc('f.period_end')->limit(1000)->get()->map(fn ($row) => [
                'metric' => $row->metric, 'cadence' => $row->cadence, 'period_end' => $row->period_end,
                'covered_stocks' => (int) $row->covered_stocks, 'eligible_stocks' => $denominator,
                'coverage_percent' => $denominator > 0 ? round(100 * (int) $row->covered_stocks / $denominator, 2) : null,
                'expected_population' => null, 'applicability' => 'unknown',
            ])->all();

        return [
            'eligible_equity_population' => $denominator, 'catalog_version' => $this->catalog->version(),
            'coverage' => $coverage, 'period_coverage' => $periodCoverage, 'period_coverage_truncated' => count($periodCoverage) === 1000,
            'unresolved_gaps' => BootstrapCoverageGap::query()->whereIn('state', ['actionable', 'reopened'])->count(),
            'unresolved_warnings' => BootstrapQualityWarning::query()->where('state', 'unresolved')->count(),
        ];
    }

    /** Explicitly reconcile a run in bounded transactions; no provider or ingestion calls occur. */
    public function reconcile(FundamentalBootstrapRun $run): array
    {
        return $this->reconcileSelection($run);
    }

    /** The automatic producer supplies one exact committed page; no provider work is performed. */
    public function reconcileJobs(FundamentalBootstrapRun $run, array $exactJobIds): array
    {
        if ($exactJobIds === [] || count($exactJobIds) > 50 || count(array_unique($exactJobIds)) !== count($exactJobIds)) {
            throw ValidationException::withMessages(['jobs' => 'Select one distinct bounded page of at most 50 jobs.']);
        }
        foreach ($exactJobIds as $id) if (! is_int($id) || $id < 1) {
            throw ValidationException::withMessages(['jobs' => 'Job IDs must be positive integers.']);
        }
        if ($run->jobs()->whereIn('id', $exactJobIds)->count() !== count($exactJobIds)) {
            throw ValidationException::withMessages(['jobs' => 'Every selected job must belong to this run.']);
        }
        return $this->reconcileSelection($run, $exactJobIds);
    }

    private function reconcileSelection(FundamentalBootstrapRun $run, ?array $exactJobIds = null): array
    {
        $createdGaps = 0;
        $observedGaps = 0;
        $resolvedGaps = 0;
        $reopenedGaps = 0;
        $createdWarnings = 0;
        $run->jobs()->when($exactJobIds !== null, fn ($query) => $query->whereIn('id', $exactJobIds))->orderBy('id')->chunkById(50, function ($jobs) use ($run, &$createdGaps, &$observedGaps, &$resolvedGaps, &$reopenedGaps, &$createdWarnings): void {
            $committed = DB::transaction(function () use ($jobs, $run): array {
                // Retry-local counters are merged only after commit, never counted twice on a deadlock retry.
                $createdGaps = $observedGaps = $resolvedGaps = $reopenedGaps = $createdWarnings = 0;
                $stockIds = $jobs->pluck('stock_id')->map(fn ($id) => (int) $id)->all();
                $metrics = $this->catalog->primaryFactKeys();
                $facts = DB::table('stox_fundamental_facts')->whereIn('stock_id', $stockIds)->where('is_current', true)
                    ->whereIn('cadence', ['quarterly', 'annual'])->whereIn('fact_key', $metrics)
                    ->select('stock_id', 'fact_key', 'cadence')->distinct()->get()
                    ->mapWithKeys(fn ($fact) => [$fact->stock_id.':'.$fact->fact_key.':'.$fact->cadence => true]);
                $provenanceMissing = DB::table('stox_fundamental_facts')->whereIn('stock_id', $stockIds)->where('is_current', true)
                    ->where(fn ($query) => $query->whereNull('source_meta')->orWhereRaw('JSON_LENGTH(source_meta) = 0'))
                    ->distinct()->pluck('stock_id')->mapWithKeys(fn ($id) => [$id => true]);
                $providerChecks = DB::table('stox_fundamental_provider_checks')->whereIn('stock_id', $stockIds)
                    ->whereIn('cadence', ['quarterly', 'annual'])
                    ->get(['stock_id', 'cadence', 'last_successful_check_at', 'provider', 'provider_symbol'])
                    ->keyBy(fn ($check) => $check->stock_id.':'.$check->cadence);

                foreach ($jobs as $job) {
                    foreach (['quarterly', 'annual'] as $cadence) {
                        foreach ($metrics as $metric) {
                            $natural = implode(':', [$job->stock_id, $metric, $cadence, 'no-period']);
                            $identity = hash('sha256', $natural);
                            $present = isset($facts[$job->stock_id.':'.$metric.':'.$cadence]);
                            $gap = BootstrapCoverageGap::query()->where('identity_hash', $identity)->lockForUpdate()->first();
                            if ($present && ! $gap) continue;
                            $reason = BootstrapGapReason::forMissingFact($job, $cadence, $metric);
                            $insertedGap = 0;
                            if (! $gap) {
                                $insertedGap = DB::table('stox_fund_bootstrap_coverage_gaps')->insertOrIgnore([
                                    'run_id' => $run->id, 'stock_id' => $job->stock_id, 'metric_key' => $metric,
                                    'cadence' => $cadence, 'period_end' => null, 'reason' => $reason,
                                    'state' => 'actionable', 'identity_hash' => $identity,
                                    'created_at' => now(), 'updated_at' => now(),
                                ]);
                                $gap = BootstrapCoverageGap::query()->where('identity_hash', $identity)->lockForUpdate()->firstOrFail();
                            }
                            $fillabilityEvidence = $present ? null : $this->fillabilityEvidence($gap, $providerChecks[$job->stock_id.':'.$cadence] ?? null);
                            DB::table('stox_fund_bootstrap_gap_observations')->upsert([[
                                'gap_id' => $gap->id, 'run_id' => $run->id, 'observed_at' => now(),
                                'observed_reason' => $reason,
                                'fillability_evidence' => $fillabilityEvidence,
                                'created_at' => now(), 'updated_at' => now(),
                            ]], ['gap_id', 'run_id'], ['observed_at', 'observed_reason', 'fillability_evidence', 'updated_at']);
                            if ($present) {
                                $gap->forceFill(['state' => 'resolved', 'evidence_at' => $gap->evidence_at ?? now(), 'reason' => $reason])->save();
                                if ($gap->wasChanged('state')) $resolvedGaps++;
                            } elseif ($fillabilityEvidence !== null && in_array($gap->state, ['accepted', 'resolved'], true)) {
                                $gap->forceFill(['state' => 'reopened', 'evidence_at' => now(), 'reason' => $reason])->save();
                                $reopenedGaps++;
                            } elseif ($insertedGap === 1) {
                                // It is intentionally actionable only on first observation. Later runs
                                // preserve accepted/resolved state absent relevant source/mapping evidence.
                                $createdGaps++;
                            } else {
                                $gap->forceFill(['reason' => $reason])->save();
                                $observedGaps++;
                            }
                        }
                    }

                    if ((int) $job->anomalies_count > 0) {
                        $identity = hash('sha256', implode(':', [$run->id, $job->id, BootstrapWarningCategory::ANOMALY_REVIEW]));
                        $warning = BootstrapQualityWarning::query()->firstOrCreate(['identity_hash' => $identity], [
                            'run_id' => $run->id, 'stock_id' => $job->stock_id, 'category' => BootstrapWarningCategory::ANOMALY_REVIEW,
                            'summary' => 'One or more facts were rejected by the existing bootstrap anomaly guard.',
                            'details' => ['anomaly_count' => (int) $job->anomalies_count], 'state' => 'unresolved',
                        ]);
                        if ($warning->wasRecentlyCreated) $createdWarnings++;
                    } elseif ((int) $job->facts_rejected > 0) {
                        $identity = hash('sha256', implode(':', [$run->id, $job->id, BootstrapWarningCategory::REJECTED_FACTS]));
                        $warning = BootstrapQualityWarning::query()->firstOrCreate(['identity_hash' => $identity], [
                            'run_id' => $run->id, 'stock_id' => $job->stock_id, 'category' => BootstrapWarningCategory::REJECTED_FACTS,
                            'summary' => 'One or more facts were rejected by the existing quality gate.',
                            'details' => ['rejected_fact_count' => (int) $job->facts_rejected], 'state' => 'unresolved',
                        ]);
                        if ($warning->wasRecentlyCreated) $createdWarnings++;
                    }
                    if (isset($provenanceMissing[$job->stock_id])) {
                        $identity = hash('sha256', implode(':', [$run->id, $job->id, BootstrapWarningCategory::MISSING_PROVENANCE]));
                        $warning = BootstrapQualityWarning::query()->firstOrCreate(['identity_hash' => $identity], [
                            'run_id' => $run->id, 'stock_id' => $job->stock_id, 'category' => BootstrapWarningCategory::MISSING_PROVENANCE,
                            'summary' => 'Current canonical facts include rows without source provenance metadata.',
                            'details' => ['affected_fact_count' => (int) DB::table('stox_fundamental_facts')->where('stock_id', $job->stock_id)->where('is_current', true)
                                ->where(fn ($query) => $query->whereNull('source_meta')->orWhereRaw('JSON_LENGTH(source_meta) = 0'))->count()],
                            'state' => 'unresolved',
                        ]);
                        if ($warning->wasRecentlyCreated) $createdWarnings++;
                    }
                }
                return compact('createdGaps', 'observedGaps', 'resolvedGaps', 'reopenedGaps', 'createdWarnings');
            }, 3);
            $createdGaps += $committed['createdGaps'];
            $observedGaps += $committed['observedGaps'];
            $resolvedGaps += $committed['resolvedGaps'];
            $reopenedGaps += $committed['reopenedGaps'];
            $createdWarnings += $committed['createdWarnings'];
        }, 'id');

        return ['run_id' => $run->id, 'created_gaps' => $createdGaps, 'observed_gaps' => $observedGaps,
            'resolved_gaps' => $resolvedGaps, 'created_warnings' => $createdWarnings, 'reopened_gaps' => $reopenedGaps];
    }

    public function gaps(array $filters = []): LengthAwarePaginator
    {
        $page = BootstrapCoverageGap::query()->with('stock:id,symbol,name')->addSelect(['observations_count' => DB::table('stox_fund_bootstrap_gap_observations')
            ->selectRaw('COUNT(*)')->whereColumn('gap_id', 'stox_fund_bootstrap_coverage_gaps.id')])
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v))
            ->when($filters['reason'] ?? null, fn ($q, $v) => $q->where('reason', $v))
            ->when($filters['metric'] ?? null, fn ($q, $v) => $q->where('metric_key', $v))
            ->when($filters['cadence'] ?? null, fn ($q, $v) => $q->where('cadence', $v))
            ->orderByDesc('id')->paginate($this->pageSize($filters));
        $page->getCollection()->transform(fn (BootstrapCoverageGap $gap) => $this->presentGap($gap));
        return $page;
    }

    public function warnings(array $filters = []): LengthAwarePaginator
    {
        $page = BootstrapQualityWarning::query()->with('stock:id,symbol,name')
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->orderByDesc('id')->paginate($this->pageSize($filters));
        $page->getCollection()->transform(fn (BootstrapQualityWarning $warning) => $this->presentWarning($warning));
        return $page;
    }

    public function acknowledgeWarnings(array $ids, int $actorId, ?string $note): int
    {
        $ids = $this->validateIds($ids);
        return DB::transaction(function () use ($ids, $actorId, $note): int {
            $this->validateActorAndNote($actorId, $note);
            $rows = BootstrapQualityWarning::query()->whereIn('id', $ids)->lockForUpdate()->get();
            $this->requireExactSelection($ids, $rows->count(), 'warnings');
            $now = now();
            $pending = BootstrapQualityWarning::query()->whereIn('id', $ids)->where('state', 'unresolved')->pluck('id');
            if ($pending->isEmpty()) return 0;
            foreach ($pending as $warningId) DB::table('stox_fund_bootstrap_warning_ack_events')->insert([
                'warning_id' => $warningId, 'actor_id' => $actorId, 'note' => $note, 'acknowledged_at' => now(),
            ]);
            return BootstrapQualityWarning::query()->whereIn('id', $pending)->update([
                'state' => 'acknowledged', 'acknowledged_by' => $actorId, 'acknowledged_at' => $now,
                'acknowledgement_note' => $note, 'updated_at' => $now,
            ]);
        }, 3);
    }

    public function acknowledgeGaps(array $ids, int $actorId, ?string $note): int
    {
        $ids = $this->validateIds($ids);
        return DB::transaction(function () use ($ids, $actorId, $note): int {
            $this->validateActorAndNote($actorId, $note);
            $rows = BootstrapCoverageGap::query()->whereIn('id', $ids)->lockForUpdate()->get();
            $this->requireExactSelection($ids, $rows->count(), 'gaps');
            if ($rows->contains(fn (BootstrapCoverageGap $row) => ! in_array($row->state, ['actionable', 'reopened', 'accepted'], true))) {
                throw ValidationException::withMessages(['ids' => 'Only unresolved gaps can be accepted.']);
            }
            if ($rows->pluck('metric_key')->unique()->count() !== 1 || $rows->pluck('cadence')->unique()->count() !== 1 || $rows->pluck('reason')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['ids' => 'Bulk gap acknowledgement requires one metric, cadence, and reason.']);
            }
            $now = now();
            $pending = $rows->where('state', '!=', 'accepted')->values();
            foreach ($pending as $row) {
                DB::table('stox_fund_bootstrap_gap_ack_events')->insert([
                    'gap_id' => $row->id, 'actor_id' => $actorId, 'note' => $note, 'acknowledged_at' => $now,
                ]);
                if ($row->state === 'accepted') continue;
                $row->forceFill(['state' => 'accepted', 'acknowledged_by' => $actorId,
                    'acknowledged_at' => $now, 'acknowledged_reason' => $row->reason, 'acknowledgement_note' => $note])->save();
            }
            return $pending->count();
        }, 3);
    }

    /** Returns A's supported whole-stock target set after strict compatibility validation. */
    public function selectedStockIdsForLaunch(array $gapIds): array
    {
        $gapIds = $this->validateIds($gapIds);
        $rows = BootstrapCoverageGap::query()->whereIn('id', $gapIds)->get();
        $this->requireExactSelection($gapIds, $rows->count(), 'gaps');
        if ($rows->contains(fn (BootstrapCoverageGap $row) => ! in_array($row->state, ['actionable', 'reopened'], true))) {
            throw ValidationException::withMessages(['gap_ids' => 'Only unresolved actionable gaps can be targeted.']);
        }
        if ($rows->pluck('metric_key')->unique()->count() !== 1 || $rows->pluck('cadence')->unique()->count() !== 1) {
            throw ValidationException::withMessages(['gap_ids' => 'Target selection must share one metric and cadence; the engine accepts stocks only.']);
        }
        return $rows->pluck('stock_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    private function presentGap(BootstrapCoverageGap $gap): array
    {
        $reasons = [BootstrapGapReason::SOURCE_UNAVAILABLE, BootstrapGapReason::METRIC_NOT_REPORTED,
            BootstrapGapReason::PERIOD_NOT_APPLICABLE, BootstrapGapReason::MAPPING_MISSING,
            BootstrapGapReason::UNSUPPORTED_CADENCE, BootstrapGapReason::NOT_ATTEMPTED,
            BootstrapGapReason::ATTEMPT_FAILED, BootstrapGapReason::PREREQUISITE_MISSING, BootstrapGapReason::EVIDENCE_UNKNOWN];
        return ['id' => (int) $gap->id, 'run_id' => (int) $gap->run_id,
            'stock' => ['id' => (int) $gap->stock_id, 'symbol' => $gap->stock?->symbol],
            'metric' => in_array($gap->metric_key, $this->catalog->primaryFactKeys(), true) ? (string) $gap->metric_key : null,
            'cadence' => in_array($gap->cadence, ['quarterly', 'annual'], true) ? (string) $gap->cadence : null,
            'period_end' => $gap->period_end?->toDateString(), 'reason' => in_array($gap->reason, $reasons, true) ? (string) $gap->reason : BootstrapGapReason::EVIDENCE_UNKNOWN,
            'state' => in_array($gap->state, ['actionable', 'reopened', 'accepted', 'resolved'], true) ? (string) $gap->state : 'actionable',
            'observation_count' => min(1000000000, max(0, (int) $gap->observations_count)),
            'observations' => DB::table('stox_fund_bootstrap_gap_observations')->where('gap_id', $gap->id)->orderByDesc('id')->limit(20)
                ->get(['run_id', 'observed_at', 'observed_reason', 'fillability_evidence'])->map(fn ($event) => [
                    'run_id' => (int) $event->run_id, 'at' => $event->observed_at,
                    'reason' => in_array($event->observed_reason, $reasons, true) ? $event->observed_reason : BootstrapGapReason::EVIDENCE_UNKNOWN,
                    'fillability_evidence' => in_array($event->fillability_evidence, ['source_available_after_acknowledgement', 'provider_symbol_mapping_after_acknowledgement'], true) ? $event->fillability_evidence : null,
                ])->all(),
            'acknowledgement' => $gap->acknowledged_at ? ['actor_id' => (int) $gap->acknowledged_by,
                'at' => $gap->acknowledged_at->toIso8601String(), 'note' => $gap->acknowledgement_note] : null,
            'evidence_at' => $gap->evidence_at?->toIso8601String(),
            'acknowledgement_history' => DB::table('stox_fund_bootstrap_gap_ack_events')->where('gap_id', $gap->id)->orderByDesc('id')->limit(20)->get(['actor_id', 'note', 'acknowledged_at'])->map(fn ($event) => ['actor_id' => (int) $event->actor_id, 'note' => $event->note, 'at' => $event->acknowledged_at])->all()];
    }

    private function presentWarning(BootstrapQualityWarning $warning): array
    {
        $category = in_array($warning->category, [BootstrapWarningCategory::REJECTED_FACTS, BootstrapWarningCategory::ANOMALY_REVIEW, BootstrapWarningCategory::MISSING_PROVENANCE], true)
            ? (string) $warning->category : 'unknown_quality_warning';
        $details = [];
        foreach (['rejected_fact_count', 'anomaly_count', 'affected_fact_count'] as $key) {
            if (isset($warning->details[$key]) && is_numeric($warning->details[$key])) $details[$key] = min(1000000000, max(0, (int) $warning->details[$key]));
        }
        return ['id' => (int) $warning->id, 'run_id' => (int) $warning->run_id,
            'stock' => ['id' => (int) $warning->stock_id, 'symbol' => $warning->stock?->symbol],
            'category' => $category,
            'summary' => $category === BootstrapWarningCategory::REJECTED_FACTS
                ? 'One or more facts were rejected by the existing quality gate.'
                : ($category === BootstrapWarningCategory::ANOMALY_REVIEW ? 'One or more facts were rejected by the existing bootstrap anomaly guard.'
                    : ($category === BootstrapWarningCategory::MISSING_PROVENANCE ? 'Current canonical facts include rows without source provenance metadata.' : 'A normalized quality warning was recorded.')),
            'details' => $details, 'state' => in_array($warning->state, ['unresolved', 'acknowledged'], true) ? (string) $warning->state : 'unresolved',
            'acknowledgement' => $warning->acknowledged_at ? ['actor_id' => (int) $warning->acknowledged_by,
                'at' => $warning->acknowledged_at->toIso8601String(), 'note' => $warning->acknowledgement_note] : null,
            'acknowledgement_history' => DB::table('stox_fund_bootstrap_warning_ack_events')->where('warning_id', $warning->id)->orderByDesc('id')->limit(20)->get(['actor_id', 'note', 'acknowledged_at'])->map(fn ($event) => ['actor_id' => (int) $event->actor_id, 'note' => $event->note, 'at' => $event->acknowledged_at])->all()];
    }

    private function validateActorAndNote(int $actorId, ?string $note): void
    {
        if (! User::query()->whereKey($actorId)->where('is_admin', true)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['actor' => 'A valid Admin identity is required.']);
        }
        if ($note !== null && (mb_strlen($note) > 1000 || trim($note) !== $note)) {
            throw ValidationException::withMessages(['note' => 'The optional note must be trimmed and at most 1000 characters.']);
        }
    }

    private function fillabilityEvidence(BootstrapCoverageGap $gap, ?object $providerCheck): ?string
    {
        if (! $providerCheck || ! $gap->acknowledged_at || $providerCheck->last_successful_check_at === null
            || ! \Carbon\Carbon::parse($providerCheck->last_successful_check_at)->gt($gap->acknowledged_at)) return null;
        $baselineReason = $gap->acknowledged_reason ?? $gap->reason;
        if ($baselineReason === BootstrapGapReason::SOURCE_UNAVAILABLE && trim((string) $providerCheck->provider) !== '') {
            return 'source_available_after_acknowledgement';
        }
        if ($baselineReason === BootstrapGapReason::MAPPING_MISSING && trim((string) $providerCheck->provider_symbol) !== '') {
            return 'provider_symbol_mapping_after_acknowledgement';
        }
        return null;
    }

    private function validateIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Select 1 to 100 distinct record IDs.']);
        }
        foreach ($ids as $id) if (! is_int($id) || $id < 1) throw ValidationException::withMessages(['ids' => 'Every record ID must be a positive integer.']);
        return $ids;
    }

    private function requireExactSelection(array $ids, int $found, string $kind): void
    {
        if ($found !== count($ids)) throw ValidationException::withMessages(['ids' => "Select existing {$kind} only."]);
    }

    private function pageSize(array $filters): int { return min(100, max(1, (int) ($filters['per_page'] ?? 25))); }
}
