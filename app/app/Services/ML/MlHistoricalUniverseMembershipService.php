<?php

namespace App\Services\ML;

use App\Models\V8\MlUniverseMembership;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Models\V8\MlUniverseSnapshotBoundary;
use App\Contracts\MlHistoricalUniverseProvider;
use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MlHistoricalUniverseMembershipService
{
    public const ACTIVE_ELIGIBLE_NSE = 'active_eligible_nse';

    /** @var array<string, list<int>> */
    private array $idsMemo = [];

    /** @var array<string, string|null> */
    private array $sectorMemo = [];

    public function resetMemo(): void
    {
        $this->idsMemo = [];
        $this->sectorMemo = [];
    }

    /** @return list<int> */
    public function stockIdsForDate(string $date, string $universeKey = self::ACTIVE_ELIGIBLE_NSE): array
    {
        $key = $universeKey.':'.$date;
        if (array_key_exists($key, $this->idsMemo)) {
            return $this->idsMemo[$key];
        }

        $this->idsMemo[$key] = MlUniverseMembership::query()
            ->where('universe_key', $universeKey)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderBy('stock_id')
            ->pluck('stock_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->idsMemo[$key];
    }

    public function sectorForDate(int $stockId, string $date, string $universeKey = self::ACTIVE_ELIGIBLE_NSE): ?string
    {
        $key = $universeKey.':'.$stockId.':'.$date;
        if (array_key_exists($key, $this->sectorMemo)) {
            return $this->sectorMemo[$key];
        }

        $this->sectorMemo[$key] = MlUniverseMembership::query()
            ->where('stock_id', $stockId)
            ->where('universe_key', $universeKey)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->value('sector_snapshot');

        $sector = trim((string) $this->sectorMemo[$key]);
        $this->sectorMemo[$key] = $sector !== '' ? $sector : null;

        return $this->sectorMemo[$key];
    }

    /**
     * Report whether the effective-dated universe has evidence for each
     * requested historical date. An empty universe and an absent snapshot are
     * intentionally different: the latter is a coverage gap and must not be
     * replaced with today's composition.
     *
     * @param list<string> $dates
     * @return array{expected_dates:list<string>,covered_dates:list<string>,missing_dates:list<string>,coverage_percentage:float,gaps:list<array{start:string,end:string,days:int}>}
     */
    public function coverageForDates(array $dates, string $universeKey = self::ACTIVE_ELIGIBLE_NSE): array
    {
        $expected = array_values(array_unique(array_map(
            static fn (string $date): string => Carbon::parse($date)->toDateString(),
            $dates,
        )));
        sort($expected);

        $covered = [];
        if ($expected !== []) {
            $intervals = MlUniverseMembership::query()
                ->where('universe_key', $universeKey)
                ->whereDate('effective_from', '<=', $expected[array_key_last($expected)])
                ->where(function ($query) use ($expected): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $expected[0]);
                })
                ->get(['effective_from', 'effective_to']);
            $boundaries = MlUniverseSnapshotBoundary::query()
                ->where('universe_key', $universeKey)
                ->get(['effective_from'])
                ->pluck('effective_from')
                ->map(fn ($date): string => Carbon::parse($date)->toDateString())
                ->intersect($expected)
                ->values()
                ->all();
            foreach ($expected as $date) {
                if (in_array($date, $boundaries, true)) {
                    $covered[] = $date;
                    continue;
                }
                foreach ($intervals as $interval) {
                    if ($interval->effective_from->toDateString() <= $date
                        && ($interval->effective_to === null || $interval->effective_to->toDateString() >= $date)) {
                        $covered[] = $date;
                        break;
                    }
                }
            }
        }

        $missing = array_values(array_diff($expected, $covered));
        $gaps = [];
        foreach ($missing as $date) {
            $last = array_key_last($gaps);
            if ($last !== null && Carbon::parse($gaps[$last]['end'])->addDay()->toDateString() === $date) {
                $gaps[$last]['end'] = $date;
                $gaps[$last]['days']++;
                continue;
            }
            $gaps[] = ['start' => $date, 'end' => $date, 'days' => 1];
        }

        return [
            'expected_dates' => $expected,
            'covered_dates' => $covered,
            'missing_dates' => $missing,
            'coverage_percentage' => $expected === [] ? 0.0 : round(count($covered) / count($expected) * 100, 4),
            'gaps' => $gaps,
        ];
    }

    /**
     * Materialize a point-in-time eligible-universe snapshot from StoX's
     * current master. This is the controlled ingestion boundary; historical
     * backfills must provide their own dated source rather than pretending the
     * current master was true in the past.
     *
     * @return array{snapshot_key:string,effective_from:string,active_count:int,closed_count:int,inserted_count:int}
     */
    public function captureCurrentEligibleSnapshot(Carbon $effectiveFrom, string $source, ?string $snapshotKey = null): array
    {
        $date = $effectiveFrom->toDateString();
        $snapshotKey ??= $source.':'.$date;
        $stocks = Stock::query()
            ->where('is_active', true)
            ->where('admin_deactivated', false)
            ->where('is_benchmark', false)
            ->where('exchange', 'NSE')
            ->orderBy('id')
            ->get(['id', 'sector']);

        return DB::transaction(function () use ($date, $snapshotKey, $source, $stocks): array {
            $closedCount = MlUniverseMembership::query()
                ->where('universe_key', self::ACTIVE_ELIGIBLE_NSE)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $date)
                ->update(['effective_to' => Carbon::parse($date)->subDay()->toDateString(), 'updated_at' => now()]);

            $insertedCount = 0;
            foreach ($stocks as $stock) {
                $membership = MlUniverseMembership::query()
                    ->where('stock_id', $stock->id)
                    ->where('universe_key', self::ACTIVE_ELIGIBLE_NSE)
                    ->whereDate('effective_from', $date)
                    ->first();
                if ($membership === null) {
                    $membership = new MlUniverseMembership([
                        'stock_id' => $stock->id,
                        'universe_key' => self::ACTIVE_ELIGIBLE_NSE,
                        'effective_from' => $date,
                    ]);
                }
                if (! $membership->exists) {
                    $insertedCount++;
                }
                $membership->fill([
                    'effective_to' => null,
                    'sector_snapshot' => $stock->sector,
                    'source' => $source,
                    'snapshot_key' => $snapshotKey,
                ])->save();
            }
            $this->recordSnapshotBoundary($date, $source, $snapshotKey, $stocks->count(), self::ACTIVE_ELIGIBLE_NSE);

            $this->resetMemo();

            return [
                'snapshot_key' => $snapshotKey,
                'effective_from' => $date,
                'active_count' => $stocks->count(),
                'closed_count' => $closedCount,
                'inserted_count' => $insertedCount,
            ];
        });
    }

    /**
     * Materialize dated membership supplied by an authoritative historical
     * source. Current Stock rows are deliberately never consulted here.
     *
     * @param list<array{effective_from:string,memberships:list<array{stock_id:int,sector_snapshot?:?string,snapshot_key?:?string}>}> $snapshots
     * @return array{run_id:int,status:string,requested_dates:list<string>,processed_dates:list<string>,failed_dates:list<string>,skipped_dates:list<string>}
     */
    public function backfillHistoricalSnapshots(array $snapshots, string $source, ?int $runId = null, string $universeKey = self::ACTIVE_ELIGIBLE_NSE): array
    {
        $normalized = [];
        foreach ($snapshots as $snapshot) {
            $date = Carbon::parse((string) ($snapshot['effective_from'] ?? ''))->toDateString();
            if (isset($normalized[$date])) {
                throw new \InvalidArgumentException("Duplicate historical universe snapshot: {$date}");
            }
            $normalized[$date] = [
                'effective_from' => $date,
                'memberships' => array_values($snapshot['memberships'] ?? []),
                'snapshot_key' => $snapshot['snapshot_key'] ?? null,
                'response_version' => $snapshot['response_version'] ?? null,
                'diagnostics' => $snapshot['diagnostics'] ?? null,
            ];
        }
        ksort($normalized);
        $requestedDates = array_keys($normalized);
        $run = $runId !== null
            ? MlUniverseSnapshotBackfillRun::query()->findOrFail($runId)
            : MlUniverseSnapshotBackfillRun::query()->create([
                'universe_key' => $universeKey,
                'source' => $source,
                'requested_dates' => $requestedDates,
                'processed_dates' => [],
                'failed_dates' => [],
                'status' => 'queued',
            ]);

        $processed = array_values(array_unique(array_map('strval', $run->processed_dates ?? [])));
        $failed = array_values(array_unique(array_map('strval', $run->failed_dates ?? [])));
        $skipped = [];
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'last_error' => null])->save();

        $date = null;
        try {
            foreach ($normalized as $date => $snapshot) {
                if (in_array($date, $processed, true) || $this->snapshotBoundaryExists($date, $universeKey)) {
                    if (! in_array($date, $processed, true)) {
                        $processed[] = $date;
                    }
                    $skipped[] = $date;
                    continue;
                }
                DB::transaction(function () use ($date, $snapshot, $source, $universeKey): void {
                    $memberships = collect($snapshot['memberships']);
                    $stockIds = $memberships->pluck('stock_id')->map(fn ($id): int => (int) $id)->values()->all();
                    if (count($stockIds) !== count(array_unique($stockIds))) {
                        throw new \InvalidArgumentException("Duplicate stock membership in historical snapshot: {$date}");
                    }
                    if ($stockIds !== [] && Stock::query()->whereIn('id', $stockIds)->count() !== count($stockIds)) {
                        throw new \InvalidArgumentException("Historical snapshot references an unknown stock: {$date}");
                    }

                    $priorOpen = MlUniverseMembership::query()
                        ->where('universe_key', $universeKey)
                        ->whereDate('effective_from', '<', $date)
                        ->whereNull('effective_to')
                        ->get();
                    foreach ($priorOpen as $membership) {
                        if (! in_array((int) $membership->stock_id, $stockIds, true)) {
                            $membership->forceFill(['effective_to' => Carbon::parse($date)->subDay()->toDateString()])->save();
                        }
                    }
                    foreach ($memberships as $entry) {
                        $stockId = (int) $entry['stock_id'];
                        $existing = MlUniverseMembership::query()
                            ->where('stock_id', $stockId)
                            ->where('universe_key', $universeKey)
                            ->whereDate('effective_from', $date)
                            ->first();
                        $existing ??= new MlUniverseMembership([
                            'stock_id' => $stockId,
                            'universe_key' => $universeKey,
                            'effective_from' => $date,
                        ]);
                        $existing->forceFill([
                            'effective_to' => null,
                            'sector_snapshot' => $entry['sector_snapshot'] ?? null,
                            'source' => $source,
                            'snapshot_key' => $entry['snapshot_key'] ?? $source.':'.$date,
                            'provider_symbol' => $entry['provider_symbol'] ?? null,
                            'provider_token' => $entry['provider_token'] ?? null,
                            'exchange' => $entry['exchange'] ?? 'NSE',
                        ])->save();
                    }
                    $this->recordSnapshotBoundary($date, $source, (string) ($snapshot['snapshot_key'] ?? $source.':'.$date), count($stockIds), $universeKey, isset($snapshot['response_version']) ? (string) $snapshot['response_version'] : null, $snapshot['diagnostics'] ?? null);
                });
                $processed[] = $date;
                $run->forceFill(['processed_dates' => array_values(array_unique($processed)), 'failed_dates' => $failed])->save();
                $this->resetMemo();
            }
        } catch (\Throwable $e) {
            $failed[] = $date ?? 'unknown';
            $run->forceFill([
                'status' => 'failed',
                'failed_dates' => array_values(array_unique($failed)),
                'processed_dates' => array_values(array_unique($processed)),
                'last_error' => substr($e->getMessage(), 0, 1000),
            ])->save();
            throw $e;
        }

        $status = count($processed) === count($requestedDates) ? 'completed' : 'partial';
        $run->forceFill([
            'status' => $status,
            'processed_dates' => array_values(array_unique($processed)),
            'failed_dates' => array_values(array_unique($failed)),
            'completed_at' => $status === 'completed' ? now() : null,
        ])->save();

        return [
            'run_id' => (int) $run->id,
            'status' => $status,
            'requested_dates' => $requestedDates,
            'processed_dates' => array_values(array_unique($processed)),
            'failed_dates' => array_values(array_unique($failed)),
            'skipped_dates' => $skipped,
        ];
    }

    /**
     * Fetch and materialize a bounded date list from the configured
     * authoritative provider. Missing dates and provider failures remain
     * durable failures; no current-universe fallback is attempted.
     *
     * @param list<string> $dates
     * @return array<string,mixed>
     */
    public function backfillFromProvider(array $dates, MlHistoricalUniverseProvider $provider, string $source, int $maxAttempts = 3, string $universeKey = self::ACTIVE_ELIGIBLE_NSE, ?int $runId = null): array
    {
        $requested = array_values(array_unique(array_map(fn (string $date): string => Carbon::parse($date)->toDateString(), $dates)));
        sort($requested);
        $run = $runId !== null ? MlUniverseSnapshotBackfillRun::query()->findOrFail($runId) : MlUniverseSnapshotBackfillRun::query()->create([
            'universe_key' => $universeKey,
            'source' => $source,
            'requested_dates' => $requested,
            'processed_dates' => [],
            'failed_dates' => [],
            'retry_counts' => [],
            'status' => 'running',
            'started_at' => now(),
        ]);
        if ($runId !== null) {
            $requested = array_values(array_unique(array_map('strval', $run->requested_dates ?? $requested)));
            sort($requested);
        }
        $failed = [];
        $retryCounts = [];
        foreach ($requested as $date) {
            $attempt = 0;
            while (true) {
                $attempt++;
                $retryCounts[$date] = $attempt;
                try {
                    $snapshot = $provider->snapshotForDate($date);
                    $this->backfillHistoricalSnapshots([$snapshot], $source, (int) $run->id, $universeKey);
                    break;
                } catch (MlHistoricalUniverseProviderException $exception) {
                    if (! $exception->retryable || $attempt >= max(1, $maxAttempts)) {
                        $failed[$date] = $exception->getMessage();
                        $run->forceFill([
                            'failed_dates' => array_keys($failed),
                            'retry_counts' => $retryCounts,
                            'last_error' => substr($exception->getMessage(), 0, 1000),
                        ])->save();
                        break;
                    }
                }
            }
        }
        $processed = array_values(array_diff($requested, array_keys($failed)));
        $run->forceFill([
            'status' => $failed === [] ? 'completed' : 'failed',
            'processed_dates' => $processed,
            'failed_dates' => array_keys($failed),
            'retry_counts' => $retryCounts,
            'completed_at' => $failed === [] ? now() : null,
        ])->save();

        return ['run_id' => (int) $run->id, ...$run->fresh()->toArray()];
    }

    private function snapshotBoundaryExists(string $date, string $universeKey): bool
    {
        return MlUniverseSnapshotBoundary::query()
            ->where('universe_key', $universeKey)
            ->whereDate('effective_from', $date)
            ->exists();
    }

    private function recordSnapshotBoundary(string $date, string $source, string $snapshotKey, int $memberCount, string $universeKey, ?string $responseVersion = null, ?array $diagnostics = null): void
    {
        $boundary = MlUniverseSnapshotBoundary::query()
            ->where('universe_key', $universeKey)
            ->whereDate('effective_from', $date)
            ->first() ?? new MlUniverseSnapshotBoundary([
                'universe_key' => $universeKey,
                'effective_from' => $date,
            ]);
        $boundary->forceFill([
            'source' => $source,
            'snapshot_key' => $snapshotKey,
            'provider_response_version' => $responseVersion,
            'member_count' => $memberCount,
            'quality_diagnostics' => $diagnostics,
        ])->save();
    }
}
