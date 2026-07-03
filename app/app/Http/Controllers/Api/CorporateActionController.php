<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CorporateAction;
use App\Models\Stock;
use App\Services\CorporateActionService;
use App\Services\StockResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CorporateActionController extends Controller
{
    public function __construct(
        protected CorporateActionService $corporateActions,
        protected StockResolverService $stocks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $query = CorporateAction::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->whereNotNull('applied_at')
            ->orderByDesc('ex_date')
            ->orderByDesc('id');

        if ($request->filled('stock_id')) {
            $query->where('stock_id', (int) $request->input('stock_id'));
        }

        return response()->json([
            'data' => $query->limit(100)->get(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $stock = $this->resolveStock($request);
        $validated = $this->validatePayload($request);

        try {
            $preview = $this->corporateActions->preview($profile, $stock, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $preview]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $stock = $this->resolveStock($request);
        $validated = $this->validatePayload($request);

        try {
            $result = $this->corporateActions->apply($profile, $stock, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result], 201);
    }

    protected function resolveStock(Request $request): Stock
    {
        if ($request->filled('stock_id')) {
            return Stock::query()->findOrFail((int) $request->input('stock_id'));
        }

        return $this->stocks->resolve($request, allowCreate: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'stock_id' => ['nullable', 'exists:portfolio_stocks,id'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'action_type' => ['required', 'in:split,bonus'],
            'ratio_from' => ['required', 'integer', 'min:1'],
            'ratio_to' => ['required', 'integer', 'min:1'],
            'ex_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'split_scope' => ['nullable', 'in:all,before_ex_date'],
        ]);
    }
}
