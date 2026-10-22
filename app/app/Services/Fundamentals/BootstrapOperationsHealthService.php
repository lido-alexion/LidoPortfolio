<?php

namespace App\Services\Fundamentals;

use App\Models\V7\BootstrapCoverageGap;
use App\Models\V7\BootstrapQualityWarning;
use App\Models\V7\BootstrapSchedule;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;

/** Read-only global counts; no counts are inferred from a paginated list. */
final class BootstrapOperationsHealthService
{
    public function __construct(private BootstrapOperationsService $operations) {}

    public function summary(): array
    {
        $managed = fn () => FundamentalBootstrapRun::query()->opsManaged();
        $active = FundamentalBootstrapRun::query()->where('status', 'running')->orderBy('id')->first();
        $lastSuccess = FundamentalBootstrapRun::query()->where('status', 'completed')->where('failed', 0)->latest('id')->first();
        $next = BootstrapSchedule::query()->where('enabled', true)->whereNotNull('next_occurrence_at')->orderBy('next_occurrence_at')->first();
        $failed = FundamentalBootstrapJob::query()->where('status', FundamentalBootstrapJob::STATUS_FAILED)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('stox_fundamental_bootstrap_jobs as recovered')
                    ->whereColumn('recovered.stock_id', 'stox_fundamental_bootstrap_jobs.stock_id')
                    ->whereColumn('recovered.id', '>', 'stox_fundamental_bootstrap_jobs.id')
                    ->whereIn('recovered.status', [
                        FundamentalBootstrapJob::STATUS_COMPLETE_GOOD,
                        FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL,
                        FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA,
                    ]);
            })->distinct()->count('stock_id');
        return [
            'active_run' => $active ? $this->operations->presentRun($active) : null,
            'queued_count' => $managed()->where('status', 'queued')->count(),
            'last_successful_run' => $lastSuccess ? $this->operations->presentRun($lastSuccess) : null,
            'unresolved_failed_items' => $failed,
            'unresolved_warnings' => BootstrapQualityWarning::query()->where('state', 'unresolved')->count(),
            'unresolved_gaps' => BootstrapCoverageGap::query()->whereIn('state', ['actionable', 'reopened'])->count(),
            'accepted_gaps' => BootstrapCoverageGap::query()->where('state', 'accepted')->count(),
            'next_schedule' => $next ? [
                'id' => $next->id, 'name' => $next->name, 'timezone' => $next->timezone,
                'next_run_at' => \Carbon\CarbonImmutable::parse($next->getRawOriginal('next_occurrence_at'), 'UTC')->format('Y-m-d\\TH:i:s\\Z'),
            ] : null,
        ];
    }
}
