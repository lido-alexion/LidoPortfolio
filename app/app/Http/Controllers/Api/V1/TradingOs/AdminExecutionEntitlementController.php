<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AutomatedExecutionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminExecutionEntitlementController extends Controller
{
    public function __construct(
        protected AutomatedExecutionEntitlementService $entitlements,
    ) {}

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'entitled' => ['required', 'boolean'],
        ]);

        $updated = $this->entitlements->setEntitled($request->user(), $user, (bool) $validated['entitled']);

        return ApiEnvelope::success([
            'id' => $updated->id,
            'name' => $updated->name,
            'email' => $updated->email,
            'is_admin' => (bool) $updated->is_admin,
            'automated_execution_entitled' => $updated->automatedExecutionEntitled(),
            'created_at' => $updated->created_at,
        ]);
    }
}
