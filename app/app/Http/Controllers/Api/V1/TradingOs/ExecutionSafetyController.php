<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Execution\ExecutionSafetyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionSafetyController extends Controller
{
    public function __construct(
        protected ExecutionSafetyService $safety,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiEnvelope::success($this->safety->snapshot($request->user()));
    }

    public function halt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->safety->halt($request->user(), $request->user(), $validated['reason'] ?? null);

        return ApiEnvelope::success($this->safety->snapshot($user));
    }

    public function recover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', 'boolean', 'accepted'],
            'totp' => ['nullable', 'string', 'max:64'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->safety->recover(
            $request->user(),
            $request->user(),
            (bool) $validated['confirm'],
            $validated['totp'] ?? null,
            $validated['recovery_code'] ?? null,
        );

        return ApiEnvelope::success($this->safety->snapshot($user));
    }

    public function quotePolicy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'live_quote_policy' => ['required', 'string', 'in:'.implode(',', [
                User::LIVE_QUOTE_POLICY_STRICT,
                User::LIVE_QUOTE_POLICY_ALLOW_CLOSE_FALLBACK,
            ])],
        ]);

        $user = $this->safety->updateQuotePolicy($request->user(), $validated['live_quote_policy']);

        return ApiEnvelope::success($this->safety->snapshot($user));
    }

    public function kiteDisconnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiEnvelope::success($this->safety->disconnect(
            $request->user(),
            $request->user(),
            $validated['reason'] ?? null,
        ));
    }

    public function cancelOpenOrdersAndDisconnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'confirm' => ['required', 'boolean', 'accepted'],
        ]);

        if (! (bool) $validated['confirm']) {
            throw new DomainException('Emergency cancellation requires explicit confirmation.', 'CONFIRMATION_REQUIRED', 422);
        }

        return ApiEnvelope::success($this->safety->cancelOpenOrdersAndDisconnect(
            $request->user(),
            $request->user(),
            $validated['reason'] ?? null,
        ));
    }
}
