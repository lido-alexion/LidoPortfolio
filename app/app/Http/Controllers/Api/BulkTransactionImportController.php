<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BulkTransactionImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkTransactionImportController extends Controller
{
    public function __construct(
        protected BulkTransactionImportService $imports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.row_id' => ['required', 'uuid'],
            'rows.*.symbol' => ['nullable', 'string', 'max:20'],
            'rows.*.stock_id' => ['nullable', 'integer'],
            'rows.*.exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.type' => ['required', 'in:buy,sell'],
            'rows.*.quantity' => ['required', 'numeric', 'gt:0'],
            'rows.*.price' => ['required', 'numeric', 'gt:0'],
            'rows.*.fees' => ['nullable', 'numeric', 'gte:0'],
            'rows.*.transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->imports->commit(
            $profile,
            $validated['batch_id'],
            $validated['rows'],
            $request->user(),
        );

        $statusCode = $result['status'] === 'already_committed' ? 200 : 201;

        return response()->json([
            'status' => $result['status'],
            'batch_id' => $result['batch_id'],
            'row_count' => $result['row_count'],
            'data' => $result['data'],
            'cash' => $result['cash'],
            'message' => $result['status'] === 'already_committed'
                ? 'This import batch was already committed; returning existing transactions.'
                : 'Imported '.$result['row_count'].' transaction(s).',
        ], $statusCode);
    }
}
