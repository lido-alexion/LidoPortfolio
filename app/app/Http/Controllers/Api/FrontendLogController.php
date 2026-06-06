<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortfolioLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrontendLogController extends Controller
{
    public function __construct(protected PortfolioLoggerService $logger) {}

    public function store(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 8192) {
            return response()->json(['message' => 'payload too large'], 422);
        }

        $validated = $request->validate([
            'level' => ['required', 'string', 'in:debug,info,warn,warning,error'],
            'message' => ['required', 'string', 'max:2000'],
            'url' => ['nullable', 'string', 'max:500'],
            'userAgent' => ['nullable', 'string', 'max:500'],
            'timestamp' => ['nullable', 'date'],
            'requestId' => ['nullable', 'string', 'max:64'],
            'extra' => ['nullable', 'array'],
            'extra.*' => ['nullable'],
        ]);

        if (is_array($validated['extra'] ?? null)) {
            $extraJson = json_encode($validated['extra']);
            if ($extraJson !== false && strlen($extraJson) > 4000) {
                return response()->json([
                    'message' => 'extra payload too large',
                ], 422);
            }
        }

        $this->logger->logFrontendPayload([
            ...$validated,
            'requestId' => $validated['requestId'] ?? $request->header('X-Request-ID'),
        ]);

        return response()->json(['message' => 'Log accepted'], 202);
    }
}
