<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IndexCatalogService;
use App\Services\IndexPresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function __construct(
        protected IndexCatalogService $catalog,
        protected IndexPresentationService $presentation,
    ) {}

    /** Explorer benchmark selector — price indexes only (excludes India VIX). */
    public function index(): JsonResponse
    {
        $primary = $this->catalog->primarySymbol();

        $defs = array_values(array_filter(
            $this->catalog->enabledDefinitions(),
            static fn (array $def): bool => ($def['tier'] ?? 'broad') !== 'volatility',
        ));

        $indexes = array_map(static function (array $def) use ($primary): array {
            return [
                'symbol' => $def['symbol'],
                'name' => $def['name'],
                'exchange' => $def['exchange'],
                'is_primary' => $def['symbol'] === $primary,
            ];
        }, $defs);

        return response()->json([
            'data' => [
                'primary_symbol' => $primary,
                'indexes' => $indexes,
            ],
        ]);
    }

    /** Indices page — broad market indexes with price metadata. */
    public function page(): JsonResponse
    {
        return response()->json([
            'data' => $this->presentation->pageOverview(),
        ]);
    }

    public function comparison(Request $request): JsonResponse
    {
        $months = (int) $request->query('months', 12);

        return response()->json([
            'data' => $this->presentation->comparison($months),
        ]);
    }

    public function constituents(string $symbol): JsonResponse
    {
        return response()->json([
            'data' => $this->presentation->constituents($symbol),
        ]);
    }
}
