<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\EquityUniverseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStockController extends Controller
{
    public function __construct(
        protected EquityUniverseService $equityUniverse,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'in:all,active,inactive,admin_deactivated'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 25), 100);
        $page = max(1, (int) ($validated['page'] ?? 1));

        $query = $this->equityUniverse->adminCatalogueQuery($validated['status'] ?? 'all');

        if (! empty($validated['exchange'])) {
            $query->where('exchange', strtoupper($validated['exchange']));
        }

        if (! empty($validated['q'])) {
            $term = '%'.strtoupper(trim($validated['q'])).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('symbol', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Stock $stock) => $this->equityUniverse->formatStockForApi($stock))
                ->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ]);
    }

    public function activate(Stock $stock): JsonResponse
    {
        if ($stock->is_benchmark) {
            return response()->json([
                'message' => 'Benchmark stocks cannot be managed from the stocks catalogue.',
            ], 422);
        }

        if (! $stock->isAdminDeactivated()) {
            return response()->json([
                'data' => $this->equityUniverse->formatStockForApi($stock),
            ]);
        }

        $stock->admin_deactivated = false;
        $stock->save();

        return response()->json([
            'data' => $this->equityUniverse->formatStockForApi($stock->fresh()),
        ]);
    }

    public function deactivate(Stock $stock): JsonResponse
    {
        if ($stock->is_benchmark) {
            return response()->json([
                'message' => 'Benchmark stocks cannot be managed from the stocks catalogue.',
            ], 422);
        }

        if ($stock->isAdminDeactivated()) {
            return response()->json([
                'data' => $this->equityUniverse->formatStockForApi($stock),
            ]);
        }

        $stock->admin_deactivated = true;
        $stock->save();

        return response()->json([
            'data' => $this->equityUniverse->formatStockForApi($stock->fresh()),
        ]);
    }
}
