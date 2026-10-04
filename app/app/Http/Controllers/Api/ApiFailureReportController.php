<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Operations\ApiFailureReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiFailureReportController extends Controller
{
    public function store(Request $request, ApiFailureReporter $reporter): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'max:12'],
            // This is a path reported by the StoX API client, never a URL.
            // Query strings and external hosts are not valid incident context.
            'endpoint' => ['required', 'string', 'max:500', 'regex:~^/api/(?!/)[A-Za-z0-9_./{}:-]+$~'],
            'status' => ['nullable', 'integer', 'between:400,599'],
            'message' => ['nullable', 'string', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:128'],
        ]);
        $reporter->observeApiFailure($data['method'], $data['endpoint'], $data['status'] ?? null, $data['message'] ?? null, [
            'trace_id' => $data['request_id'] ?? $request->header('X-Request-ID'),
            'skip_reporting' => false,
        ]);
        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
