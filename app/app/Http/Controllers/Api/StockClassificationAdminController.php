<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\StockClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockClassificationAdminController extends Controller
{
    public function __construct(private readonly StockClassificationService $classifications) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $query = Stock::query()->where('is_benchmark', false)->orderBy('symbol');
        if (($validated['q'] ?? '') !== '') {
            $term = '%'.strtoupper(trim($validated['q'])).'%';
            $query->where(fn ($q) => $q->where('symbol', 'like', $term)->orWhere('name', 'like', $term));
        }
        return response()->json(['data' => $query->limit(50)->get()->map(fn (Stock $stock) => $this->row($stock))->values()]);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->classifications->options($request->string('sector')->toString() ?: null)]);
    }

    public function refresh(Stock $stock): JsonResponse
    {
        return response()->json(['data' => $this->classifications->refresh($stock)]);
    }

    public function saveOverride(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'sector' => ['required', 'string', 'max:160'],
            'industry' => ['required', 'string', 'max:160'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['data' => $this->classifications->saveOverride($stock, (int) $request->user()->id, $validated['sector'], $validated['industry'], $validated['reason'] ?? null)]);
    }

    public function removeOverride(Request $request, Stock $stock): JsonResponse
    {
        return response()->json(['data' => $this->classifications->removeOverride($stock, (int) $request->user()->id)]);
    }

    /** @return array<string,mixed> */
    private function row(Stock $stock): array
    {
        return [
            'id' => $stock->id, 'symbol' => $stock->symbol, 'name' => $stock->name,
            'exchange' => $stock->exchange, 'isin' => $stock->isin,
            'classification' => $this->classifications->classificationFor($stock),
        ];
    }
}
