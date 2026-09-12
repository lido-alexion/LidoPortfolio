<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Models\V7\FundamentalUpdateRun;
use Illuminate\Support\Facades\DB;
use Throwable;

class FundamentalUpdateService
{
    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalDataProvider $provider,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $settings = $this->fundamentals->settings();
        $latest = FundamentalUpdateRun::query()->latest('id')->first();
        $coverage = DB::table('stox_fundamental_facts')
            ->selectRaw('cadence, count(distinct stock_id) as stocks, max(period_end) as latest_period_end, max(availability_date) as latest_availability_date')
            ->groupBy('cadence')
            ->get()
            ->keyBy('cadence')
            ->map(fn ($row) => [
                'stocks' => (int) $row->stocks,
                'latest_period_end' => $row->latest_period_end,
                'latest_availability_date' => $row->latest_availability_date,
            ]);

        return [
            'settings' => $settings->toArray(),
            'latest_run' => $latest?->toArray(),
            'coverage' => [
                FundamentalDataService::CADENCE_QUARTERLY => $coverage->get(FundamentalDataService::CADENCE_QUARTERLY, ['stocks' => 0]),
                FundamentalDataService::CADENCE_ANNUAL => $coverage->get(FundamentalDataService::CADENCE_ANNUAL, ['stocks' => 0]),
            ],
            'queued_jobs' => FundamentalUpdateJob::query()->whereIn('status', ['queued', 'retry'])->count(),
            'running' => FundamentalUpdateRun::query()->where('status', 'running')->exists(),
        ];
    }

    public function createRun(string $trigger = 'manual', string $scope = 'incremental', ?int $stockId = null, int $limit = 25): FundamentalUpdateRun
    {
        $query = Stock::query()->effectivelyActive()->orderBy('id');
        if ($stockId !== null) {
            $query->whereKey($stockId);
        } else {
            $query->limit(max(1, min($limit, 200)));
        }

        return DB::transaction(function () use ($trigger, $scope, $query): FundamentalUpdateRun {
            $run = FundamentalUpdateRun::query()->create([
                'trigger' => $trigger,
                'scope' => $scope,
                'status' => 'queued',
            ]);

            $requested = 0;
            foreach ($query->get(['id']) as $stock) {
                foreach ([FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL] as $cadence) {
                    FundamentalUpdateJob::query()->firstOrCreate([
                        'run_id' => $run->id,
                        'stock_id' => $stock->id,
                        'cadence' => $cadence,
                    ], [
                        'status' => 'queued',
                        'priority_tier' => 8,
                    ]);
                    $requested++;
                }
            }

            $run->forceFill(['requested' => $requested])->save();

            return $run->fresh();
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function process(FundamentalUpdateRun $run, int $batch = 25): array
    {
        $settings = $this->fundamentals->settings();
        if ($settings->paused) {
            $run->forceFill(['status' => 'paused'])->save();

            return ['status' => 'paused', 'processed' => 0];
        }

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        $jobs = FundamentalUpdateJob::query()
            ->where('run_id', $run->id)
            ->whereIn('status', ['queued', 'retry'])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('priority_tier')
            ->orderBy('id')
            ->limit(max(1, min($batch, 200)))
            ->get();

        foreach ($jobs as $job) {
            $job->forceFill([
                'status' => 'running',
                'attempts' => $job->attempts + 1,
                'last_attempted_at' => now(),
            ])->save();

            try {
                $stock = Stock::query()->findOrFail($job->stock_id);
                if (! $this->needsFetch($stock, $job->cadence)) {
                    $job->forceFill(['status' => 'skipped'])->save();
                    $run->increment('skipped');
                    $run->increment('processed');
                    continue;
                }

                $rows = $this->provider->fetch($stock, $job->cadence);
                $stats = $this->fundamentals->storeFacts($stock, $rows);
                $job->forceFill(['status' => 'completed', 'last_error' => null])->save();
                $run->increment('succeeded');
                $run->increment('processed');
                $run->forceFill(['stats_json' => $this->mergeStats($run->stats_json ?? [], $stats)])->save();
            } catch (Throwable $error) {
                $willRetry = $job->attempts < $settings->max_attempts;
                $job->forceFill([
                    'status' => $willRetry ? 'retry' : 'failed',
                    'next_attempt_at' => $willRetry ? now()->addMinutes((int) pow(2, max(0, $job->attempts - 1))) : null,
                    'last_error' => $error->getMessage(),
                ])->save();
                if (! $willRetry) {
                    $run->increment('failed');
                    $run->increment('processed');
                }
                $run->forceFill(['last_error' => $error->getMessage()])->save();
            }

            if ($settings->request_delay_ms > 0) {
                usleep($settings->request_delay_ms * 1000);
            }
        }

        $remaining = FundamentalUpdateJob::query()
            ->where('run_id', $run->id)
            ->whereIn('status', ['queued', 'retry', 'running'])
            ->count();
        if ($remaining === 0) {
            $run->forceFill([
                'status' => $run->failed > 0 ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
            ])->save();
        }

        return $run->fresh()->toArray();
    }

    private function needsFetch(Stock $stock, string $cadence): bool
    {
        $latest = DB::table('stox_fundamental_facts')
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->max('first_fetched_at');

        if ($latest === null) {
            return true;
        }

        $hours = $cadence === FundamentalDataService::CADENCE_ANNUAL ? 24 * 14 : 24 * 3;

        return now()->diffInHours(\Carbon\Carbon::parse($latest)) >= $hours;
    }

    /** @param array<string,mixed> $current @param array<string,int> $stats */
    private function mergeStats(array $current, array $stats): array
    {
        foreach ($stats as $key => $value) {
            $current[$key] = (int) ($current[$key] ?? 0) + (int) $value;
        }

        return $current;
    }
}
