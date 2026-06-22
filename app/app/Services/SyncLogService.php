<?php

namespace App\Services;

use App\Models\SyncLog;
use App\Models\SyncRun;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncLogService
{
    public const JOB_DAILY_MARKET_DATA = 'daily-market-data';

    public const JOB_STOCK_MASTER = 'stock-master';

    public function __construct(
        protected SettingsService $settings,
        protected PortfolioLoggerService $portfolioLogger,
    ) {}

    public function retentionDays(): int
    {
        $raw = $this->settings->get('sync_log_retention_days', '7');
        $days = is_numeric($raw) ? (int) $raw : 7;

        return max(0, min(90, $days));
    }

    public function isEnabled(): bool
    {
        return $this->retentionDays() > 0 && Schema::hasTable('portfolio_sync_runs');
    }

    public function prune(): int
    {
        if (! Schema::hasTable('portfolio_sync_runs')) {
            return 0;
        }

        $days = $this->retentionDays();
        if ($days <= 0) {
            return SyncRun::query()->delete();
        }

        $cutoff = now()->subDays($days);

        return SyncRun::query()->where('started_at', '<', $cutoff)->delete();
    }

    public function beginRun(string $jobName): ?string
    {
        $this->prune();

        if (! $this->isEnabled()) {
            return null;
        }

        $runId = (string) Str::uuid();

        SyncRun::query()->create([
            'id' => $runId,
            'job_name' => $jobName,
            'status' => 'running',
            'started_at' => now(),
        ]);

        return $runId;
    }

    public function log(?string $runId, string $jobName, string $level, string $message, array $context = []): void
    {
        $contextWithRun = $runId ? array_merge($context, ['run_id' => $runId]) : $context;
        $this->portfolioLogger->scheduler($level, $message, $contextWithRun);

        if (! $this->isEnabled() || ! $runId || ! Schema::hasTable('portfolio_sync_logs')) {
            return;
        }

        SyncLog::query()->create([
            'run_id' => $runId,
            'job_name' => $jobName,
            'level' => strtolower($level),
            'message' => $message,
            'context' => $context ?: null,
            'logged_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int|null>  $stats
     */
    public function completeRun(
        ?string $runId,
        string $status,
        array $stats = [],
        ?string $summary = null,
    ): void {
        if (! $runId || ! Schema::hasTable('portfolio_sync_runs')) {
            return;
        }

        $normalized = in_array($status, ['success', 'partial', 'failed'], true) ? $status : 'failed';

        SyncRun::query()->where('id', $runId)->update([
            'status' => $normalized,
            'finished_at' => now(),
            'stocks_processed' => $stats['stocks_processed'] ?? $stats['processed'] ?? null,
            'failures' => $stats['failures'] ?? $stats['failed'] ?? null,
            'skipped' => $stats['skipped'] ?? null,
            'summary' => $summary,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRunSummary(?string $jobName = null): ?array
    {
        if (! Schema::hasTable('portfolio_sync_runs')) {
            return null;
        }

        $query = SyncRun::query()->orderByDesc('started_at');
        if ($jobName) {
            $query->where('job_name', $jobName);
        }

        $run = $query->first();
        if (! $run) {
            return null;
        }

        return $this->formatRun($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRun(SyncRun $run): array
    {
        return [
            'run_id' => $run->id,
            'job_name' => $run->job_name,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'stocks_processed' => $run->stocks_processed,
            'failures' => $run->failures,
            'skipped' => $run->skipped,
            'summary' => $run->summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyLogFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['level'])) {
            $query->where('level', strtolower((string) $filters['level']));
        }

        if (! empty($filters['job_name'])) {
            $query->where('job_name', (string) $filters['job_name']);
        }

        if (! empty($filters['run_id'])) {
            $query->where('run_id', (string) $filters['run_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('message', 'like', $term)
                    ->orWhere('context', 'like', $term);
            });
        }

        $timezone = $this->settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';

        if (! empty($filters['date_from'])) {
            $from = Carbon::parse((string) $filters['date_from'], $timezone)->startOfDay()->utc();
            $query->where('logged_at', '>=', $from);
        }

        if (! empty($filters['date_to'])) {
            $to = Carbon::parse((string) $filters['date_to'], $timezone)->endOfDay()->utc();
            $query->where('logged_at', '<=', $to);
        }

        return $query;
    }
}
