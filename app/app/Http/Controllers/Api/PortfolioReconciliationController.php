<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioReconciliationRun;
use App\Services\Reconciliation\PortfolioReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PortfolioReconciliationController extends Controller
{
    public function __construct(private PortfolioReconciliationService $reconciliation) {}

    public function index(): JsonResponse
    {
        $profile = \activePortfolio();

        return response()->json(['data' => [
            'status' => $this->status($profile),
            'runs' => PortfolioReconciliationRun::query()->where('profile_id', $profile->id)
                ->orderByDesc('id')->limit(100)->get(),
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
}
