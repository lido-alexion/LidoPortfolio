<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapGapReason;
use App\Services\Fundamentals\BootstrapWarningCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin-only, bounded CSV projection of one managed OPS001 run. */
final class BootstrapOperationsExportController extends Controller
{
    private const MAX_ROWS = 50000;
    private const CHUNK_SIZE = 250;
    private const COLUMNS = [
        'row_type', 'run_id', 'run_status', 'trigger', 'scope', 'requested', 'completed', 'failed',
        'stock_symbol', 'item_status', 'failure_reason', 'quarterly_status', 'annual_status',
        'period_from', 'period_to', 'warning_categories', 'gap_categories',
    ];

    public function __invoke(Request $request, int $run): StreamedResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:queued,running,retry,complete_good,complete_partial,complete_no_data,failed,cancelled'],
            'failed_only' => ['sometimes', 'boolean'],
        ]);
        $managedRun = FundamentalBootstrapRun::query()->opsManaged()->whereKey($run)->firstOrFail();
        $jobs = FundamentalBootstrapJob::query()->where('run_id', $managedRun->id)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filter_var($filters['failed_only'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($query) => $query->where('status', FundamentalBootstrapJob::STATUS_FAILED));
        $rowCount = (clone $jobs)->count();
        abort_if($rowCount > self::MAX_ROWS, 422, 'This run exceeds the operational CSV row limit. Narrow the item status filter.');

        return response()->streamDownload(function () use ($managedRun, $jobs): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) return;
            try {
                $this->write($stream, self::COLUMNS);
                $this->write($stream, [
                    'run_summary', $managedRun->id, $managedRun->status, $managedRun->trigger, $managedRun->scope,
                    $managedRun->requested, $managedRun->completed, $managedRun->failed,
                    '', '', '', '', '', '', '', '', '',
                ]);
                $jobs->with('stock:id,symbol')->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($items) use ($stream, $managedRun): void {
                    $stockIds = $items->pluck('stock_id')->unique()->values();
                    $warnings = DB::table('stox_fund_bootstrap_quality_warnings')->where('run_id', $managedRun->id)->whereIn('stock_id', $stockIds)
                        ->select('stock_id', 'category')->distinct()->get()->groupBy('stock_id');
                    $warningCategories = [BootstrapWarningCategory::REJECTED_FACTS, BootstrapWarningCategory::ANOMALY_REVIEW, BootstrapWarningCategory::MISSING_PROVENANCE];
                    $warningLabels = $warnings->map(fn ($rows) => $rows->pluck('category')
                        ->map(fn ($category) => in_array($category, $warningCategories, true) ? $category : 'unknown_quality_warning')
                        ->unique()->sort()->implode('|'));
                    $gapReasons = DB::table('stox_fund_bootstrap_gap_observations as observations')
                        ->join('stox_fund_bootstrap_coverage_gaps as gaps', 'gaps.id', '=', 'observations.gap_id')
                        ->where('observations.run_id', $managedRun->id)->whereIn('gaps.stock_id', $stockIds)
                        ->select('gaps.stock_id', 'observations.observed_reason')->distinct()->get()->groupBy('stock_id');
                    $allowedReasons = [BootstrapGapReason::SOURCE_UNAVAILABLE, BootstrapGapReason::METRIC_NOT_REPORTED,
                        BootstrapGapReason::PERIOD_NOT_APPLICABLE, BootstrapGapReason::MAPPING_MISSING,
                        BootstrapGapReason::UNSUPPORTED_CADENCE, BootstrapGapReason::NOT_ATTEMPTED,
                        BootstrapGapReason::ATTEMPT_FAILED, BootstrapGapReason::PREREQUISITE_MISSING, BootstrapGapReason::EVIDENCE_UNKNOWN];
                    $gapLabels = $gapReasons->map(fn ($rows) => $rows->pluck('observed_reason')
                        ->map(fn ($reason) => in_array($reason, $allowedReasons, true) ? $reason : BootstrapGapReason::EVIDENCE_UNKNOWN)
                        ->unique()->sort()->implode('|'));
                    foreach ($items as $job) {
                        $this->write($stream, [
                            'item', $managedRun->id, $managedRun->status, $managedRun->trigger, $managedRun->scope,
                            '', '', '', $job->stock?->symbol ?? '', $job->status, $this->normalizeFailure($job->last_error),
                            $job->quarterly_status, $job->annual_status, $job->earliest_period?->toDateString(),
                            $job->latest_period?->toDateString(), $warningLabels->get($job->stock_id, ''), $gapLabels->get($job->stock_id, ''),
                        ]);
                    }
                }, 'id');
            } finally {
                fclose($stream);
            }
        }, 'stox-bootstrap-run-'.$managedRun->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** @param resource $stream @param list<mixed> $cells */
    private function write($stream, array $cells): void
    {
        $safe = array_map(fn ($cell) => $this->safeStringCell($cell), $cells);
        fputcsv($stream, $safe, ',', '"', '', "\r\n");
    }

    private function safeStringCell(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : '';
        return preg_match('/^[\s\x00-\x20]*[=+\-@]/u', $text) ? "'".$text : $text;
    }

    private function normalizeFailure(?string $error): string
    {
        if (! $error) return '';
        $normalized = mb_strtolower($error);
        return match (true) {
            str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out') => 'Provider request timed out.',
            str_contains($normalized, 'rate limit') || str_contains($normalized, 'too many requests') => 'Provider rate limit reached.',
            str_contains($normalized, 'unavailable') || str_contains($normalized, 'connection refused') => 'Provider is unavailable.',
            str_contains($normalized, 'invalid') || str_contains($normalized, 'malformed') => 'Provider response was invalid.',
            default => 'Bootstrap item failed; inspect the protected operational logs.',
        };
    }
}
