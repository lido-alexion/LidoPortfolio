<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\HoldingPresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockPriceController extends Controller
{
    public function __construct(protected HoldingPresentationService $presentation) {}

    public function index(Request $request, Stock $stock): JsonResponse
    {
        return response()->json($this->presentation->priceHistoryForHolding($request->user(), $stock));
    }
}
