<?php

namespace App\Services\ML;

use App\Models\Setting;
use App\Models\StockPrice;
use App\Services\IndexCatalogService;
use App\Services\PortfolioLoggerService;
use App\Services\StockPriceHistoryService;
use App\Services\SyncLogService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Operator-controlled, primary-benchmark-only history campaign for V7 ML.
 * It is independent from the global index history default and is safe to
 * rerun because StockPriceHistoryService fetches only missing ranges.
 */
class PrimaryMlBenchmarkHistoryBackfillService
{
    public const JOB_NAME = 'ml-primary-benchmark-history-backfill';

    public const KEY_LAST_RUN_JSON = 'ml_primary_benchmark_history_last_run_json';

    private const LOCK_KEY = 'stox-ml-primary-benchmark-history-backfill';

    private const LOCK_SECONDS = 14400;

    public function __construct(
        protected IndexCatalogService $catalog,
        protected StockPriceHistoryService $history,
        protected SyncLogService $syncLog,
        protected PortfolioLoggerService $logger,
        protected MlBenchmarkHistoryPolicy $policy,
    ) {}

    /** @return array<string,mixed> */
    public function plan(?Carbon $to = null, ?Carbon $from = null): array
    {
        $to ??= TradingCalendar::lastRequiredPriceSession();
        $required = $this->policy->requiredRange($to);
        $from ??= $required['from'];

        return $this->rangeReport($from, $to, $required['policy'], dryRun: true);
    }

    /** @return array<string,mixed> */
    public function run(?Carbon $to = null, ?Carbon $from = null, bool $dryRun = false): array
    {
        $to ??= TradingCalendar::lastRequiredPriceSession();
        $required = $this->policy->requiredRange($to);
        $from ??= $required['from'];

        if ($from->gt($to)) {
            throw new RuntimeException('Primary benchmark history start must not be after the end date.');
        }
        if ($from->gt($required['from'])) {
            throw new RuntimeException(sprintf(
                'Requested primary benchmark range is shallower than the V7 policy. Use --from=%s or an earlier date.',
                $required['from']->toDateString(),
            ));
        }

        if ($dryRun) {
            return $this->rangeReport($from, $to, $required['policy'], dryRun: true);
        }

        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(15, function () use ($from, $to, $required): array {
            $symbol = $this->catalog->primarySymbol();
            $stock = $this->catalog->primaryBenchmarkStock();
            $runId = $this->syncLog->beginRun(self::JOB_NAME);
            $startedAt = now();

            try {
                $result = $this->history->fetchMissingHistory(
                    $stock,
                    $from->copy(),
                    $to->copy(),
                    notifyTelegramOnFailure: false,
                    includePreListingPrefix: true,
                );
                $report = $this->rangeReport($from, $to, $required['policy'], dryRun: false);
                $report['symbol'] = $symbol;
                $report['result'] = $result;
                $report['started_at'] = $startedAt->toIso8601String();
                $report['completed_at'] = now()->toIso8601String();
                $report['success'] = (bool) ($result['success'] ?? false);
                $report['cache_hit'] = (bool) ($result['cache_hit'] ?? false);

                $status = $report['success'] ? 'success' : 'failed';
                $summary = sprintf(
                    'Primary ML benchmark history %s: %s %s→%s, stored=%d, remaining_ranges=%d',
                    $status,
                    $symbol,
                    $from->toDateString(),
                    $to->toDateString(),
                    (int) ($result['stored_rows'] ?? 0),
                    (int) ($result['gaps_remaining'] ?? 0),
                );
                $this->syncLog->log($runId, self::JOB_NAME, $report['success'] ? 'info' : 'warning', $summary, [
                    'symbol' => $symbol,
                    'requested_from' => $from->toDateString(),
                    'requested_to' => $to->toDateString(),
                    'policy' => $required['policy'],
                    'stored_range' => $report['stored_range'],
                    'result' => [
                        'stored_rows' => (int) ($result['stored_rows'] ?? 0),
                        'fetched_rows' => (int) ($result['fetched_rows'] ?? 0),
                        'gaps_remaining' => (int) ($result['gaps_remaining'] ?? 0),
                        'errors' => array_slice((array) ($result['errors'] ?? []), 0, 5),
                    ],
                ]);
                $this->syncLog->completeRun($runId, $status, [
                    'stocks_processed' => 1,
                    'failures' => $report['success'] ? 0 : 1,
                    'stored_rows' => (int) ($result['stored_rows'] ?? 0),
                ], $summary);
                $this->logger->scheduler($report['success'] ? 'info' : 'warning', $summary, [
                    'category' => 'MlPrimaryBenchmarkHistory',
                    'symbol' => $symbol,
                    'policy' => $required['policy'],
                ]);
                Setting::setValue(self::KEY_LAST_RUN_JSON, json_encode($report, JSON_THROW_ON_ERROR));

                return $report;
            } catch (\Throwable $e) {
                $this->syncLog->completeRun($runId, 'failed', ['stocks_processed' => 1, 'failures' => 1], $e->getMessage());
                throw $e;
            }
        });
    }

    /** @return array<string,mixed> */
    protected function rangeReport(Carbon $from, Carbon $to, array $policy, bool $dryRun): array
    {
        $stock = $this->catalog->primaryBenchmarkStock();
        $bounds = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->selectRaw('MIN(price_date) as min_date, MAX(price_date) as max_date, COUNT(*) as row_count')
            ->first();

        return [
            'dry_run' => $dryRun,
            'symbol' => $stock->symbol,
            'requested_range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'policy' => $policy,
            'stored_range' => [
                'from' => $bounds?->min_date ? Carbon::parse($bounds->min_date)->toDateString() : null,
                'to' => $bounds?->max_date ? Carbon::parse($bounds->max_date)->toDateString() : null,
                'rows' => (int) ($bounds->row_count ?? 0),
            ],
        ];
    }
}
