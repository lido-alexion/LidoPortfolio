<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Operations\ApiFailureReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrowserErrorReportController extends Controller
{
    public function store(Request $request, ApiFailureReporter $reporter): JsonResponse
    {
        if (strlen($request->getContent()) > 512) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $allowed = ['type'];
        if (array_diff(array_keys($request->all()), $allowed)) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        $data = $request->validate([
            'type' => ['required', 'string', 'in:react_render,uncaught_exception,unhandled_rejection'],
        ]);

        $reporter->observeBrowserFault($data['type']);

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
