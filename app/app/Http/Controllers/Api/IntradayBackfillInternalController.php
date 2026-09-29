<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ML\IntradayBackfillCheckpointService;
use App\Services\ML\IntradayHistoricalPlatformService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntradayBackfillInternalController extends Controller
{
    public function plan(IntradayHistoricalPlatformService $platform): JsonResponse
    {
        return response()->json(['data' => $platform->status()]);
    }

    public function upsertCheckpoint(Request $request, IntradayBackfillCheckpointService $checkpoints): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'exchange' => ['nullable', 'string', 'max:16'],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date'],
            'status' => ['required', 'in:pending,running,complete,failed'],
            'bars_written' => ['nullable', 'integer', 'min:0'],
            'last_error' => ['nullable', 'array'],
            'last_attempt_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $checkpoint = $checkpoints->upsert($validated);

        return response()->json(['data' => $checkpoint->toArray()]);
    }

    public function listCheckpoints(IntradayBackfillCheckpointService $checkpoints): JsonResponse
    {
        if (request()->query('symbol') !== null) {
            $checkpoint = $checkpoints->find(
                (string) request()->query('symbol'),
                (string) request()->query('exchange', 'NSE'),
                request()->query('window_start'),
                request()->query('window_end'),
            );

            return response()->json(['data' => $checkpoint?->toArray()]);
        }

        return response()->json(['data' => $checkpoints->recent()]);
    }

    public function control(IntradayBackfillCheckpointService $checkpoints): JsonResponse
    {
        return response()->json(['data' => $checkpoints->control()->toArray()]);
    }
}
