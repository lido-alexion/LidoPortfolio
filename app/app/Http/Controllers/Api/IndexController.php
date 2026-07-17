<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IndexCatalogService;
use Illuminate\Http\JsonResponse;

class IndexController extends Controller
{
    public function __construct(protected IndexCatalogService $catalog) {}

    public function index(): JsonResponse
    {
        $primary = $this->catalog->primarySymbol();

        $indexes = array_map(static function (array $def) use ($primary): array {
            return [
                'symbol' => $def['symbol'],
                'name' => $def['name'],
                'exchange' => $def['exchange'],
                'is_primary' => $def['symbol'] === $primary,
            ];
        }, $this->catalog->enabledDefinitions());

        return response()->json([
            'data' => [
                'primary_symbol' => $primary,
                'indexes' => $indexes,
            ],
        ]);
    }
}
