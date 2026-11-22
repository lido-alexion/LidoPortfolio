<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioReconciliationRun;
use App\Services\Reconciliation\PortfolioReconciliationService;
use App\Services\Reconciliation\KiteCncTradeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PortfolioReconciliationController extends Controller
{
    public function __construct(private PortfolioReconciliationService $reconciliation, private KiteCncTradeSyncService $tradeSync) {}

    public function index(): JsonResponse
    {
        $profile = \activePortfolio();
        $runs = PortfolioReconciliationRun::query()->where('profile_id', $profile->id)
            ->orderByDesc('id')->limit(100)->get();

        $latestTradeSync = \App\Models\KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)->latest('id')->first();
        $tradeReview = \App\Models\KiteCncTradeImport::query()->where('profile_id', $profile->id)
            ->where('status', 'manual_match_review')->latest('id')->limit(25)->get();
        $tradeGaps = \App\Models\KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)
            ->where('status', 'gap_unavailable')->orderByDesc('trading_date')->limit(20)->get(['trading_date', 'failure']);
        return response()->json(['data' => [
            'status' => $this->status($profile),
            'runs' => $this->compactRuns($runs),
            'trade_sync' => $latestTradeSync,
            'trade_review' => $tradeReview,
            'trade_gaps' => $tradeGaps,
        ]]);
    }

    public function show(int $run): JsonResponse
    {
        return response()->json(['data' => PortfolioReconciliationRun::query()
            ->where('profile_id', \activePortfolio()->id)->findOrFail($run)]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $run = $this->reconciliation->run(\activePortfolio(), 'manual');

            return response()->json(['data' => [
                'status' => $this->status(\activePortfolio()->fresh()),
                'run' => $run,
            ]], 201);
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'already in progress')) {
                return response()->json(['message' => $error->getMessage()], 409);
            }
            throw $error;
        }
    }

    public function syncTrades(): JsonResponse
    {
        try {
            $result = $this->tradeSync->sync(\activePortfolio(), 'manual');
            return response()->json(['data' => $result], $result['run']->status === 'sync_failed' ? 503 : 200);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'already in progress')) {
                return response()->json(['message' => $error->getMessage()], 409);
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function status($profile): array
    {
        return [
            'holdings' => $profile->reconciliation_holdings_status,
            'funds' => $profile->reconciliation_funds_status,
            'overall' => $profile->reconciliation_overall_status,
            'execution_blocked' => (bool) $profile->execution_blocked_by_reconciliation,
            'last_successful_at' => $profile->last_successful_reconciliation_at?->toISOString(),
            'last_failure' => $profile->last_reconciliation_failure,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,PortfolioReconciliationRun> $runs
     * @return list<PortfolioReconciliationRun>
     */
    private function compactRuns($runs): array
    {
        $compacted = [];
        $seenScheduledFailures = [];

        foreach ($runs as $run) {
            if ($run->trigger === 'scheduled' && $run->status === 'sync_failed') {
                $key = implode('|', [
                    $run->completed_at?->toDateString() ?? 'unknown',
                    (string) $run->failure,
                ]);
                if (isset($seenScheduledFailures[$key])) {
                    continue;
                }
                $seenScheduledFailures[$key] = true;
            }

            $compacted[] = $run;
        }

        return $compacted;
    }
}
