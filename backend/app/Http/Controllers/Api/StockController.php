<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StockValidationUserMessage;
use App\Models\Stock;
use App\Services\StockValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(protected StockValidationService $validation) {}

    public function index(Request $request): JsonResponse
    {
        $query = Stock::query()
            ->where('is_benchmark', false)
            ->where('is_active', true);

        if ($request->filled('q')) {
            $term = '%'.strtoupper(trim((string) $request->input('q'))).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('symbol', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        $stocks = $query
            ->orderBy('symbol')
            ->limit(min((int) $request->input('limit', 50), 100))
            ->get();

        return response()->json(['data' => $stocks]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:40'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $term = strtoupper(trim($validated['q']));
        $like = '%'.$term.'%';

        $query = Stock::query()
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->where(function ($builder) use ($like) {
                $builder->where('symbol', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });

        if (! empty($validated['exchange'])) {
            $query->where('exchange', $validated['exchange']);
        }

        $stocks = $query
            ->orderByRaw('CASE WHEN symbol LIKE ? THEN 0 ELSE 1 END', [$term.'%'])
            ->orderBy('symbol')
            ->limit($validated['limit'] ?? 20)
            ->get(['id', 'symbol', 'exchange', 'name']);

        return response()->json(['data' => $stocks]);
    }

    public function validateSymbol(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'name' => ['nullable', 'string', 'max:255'],
            'check_only' => ['sometimes', 'boolean'],
        ]);

        $exchange = $validated['exchange'] ?? 'NSE';
        $checkOnly = $request->boolean('check_only');

        $result = $checkOnly
            ? $this->validation->validate($validated['symbol'], $exchange, true)
            : $this->validation->validateAndPersist(
                $validated['symbol'],
                $exchange,
                $validated['name'] ?? null,
            );

        if (! $result->valid) {
            return response()->json([
                'valid' => false,
                'message' => StockValidationUserMessage::fromErrors(
                    $result->errors,
                    $validated['symbol'],
                    $exchange,
                ),
                'errors' => StockValidationUserMessage::normalizeErrors($result->errors),
            ], 422);
        }

        return response()->json([
            'valid' => true,
            'source' => $result->source,
            'data' => $result->stock,
            'meta' => $result->meta,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'name' => ['nullable', 'string', 'max:255'],
            'isin' => ['nullable', 'string', 'max:20'],
            'sector' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->validation->validateAndPersist(
            $validated['symbol'],
            $validated['exchange'] ?? 'NSE',
            $validated['name'] ?? null,
            $validated['isin'] ?? null,
            $validated['sector'] ?? null,
        );

        if (! $result->valid || ! $result->stock) {
            return response()->json([
                'message' => 'Stock validation failed',
                'errors' => $result->errors,
            ], 422);
        }

        return response()->json(['data' => $result->stock], 201);
    }

    public function show(Stock $stock): JsonResponse
    {
        return response()->json(['data' => $stock->load('metrics')]);
    }

    public function update(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $stock->update($validated);

        return response()->json(['data' => $stock->fresh()]);
    }
}
