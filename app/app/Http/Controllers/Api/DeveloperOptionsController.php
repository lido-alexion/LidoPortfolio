<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GuidedTour\GuidedTourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** TEMPORARY TEST/DEVELOPMENT INFRASTRUCTURE; remove before final public hardening. */
class DeveloperOptionsController extends Controller
{
    public function resetGuidedTour(Request $request, GuidedTourService $tour): JsonResponse
    {
        // No target identity is accepted. Browser storage is never authorization.
        if ($request->all() !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                array_keys($request->all()),
                ['This action accepts no input; it only resets the authenticated user.'],
            ));
        }

        return response()->json(['data' => $tour->resetFor($request->user())]);
    }
}
