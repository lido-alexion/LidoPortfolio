<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Security\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TotpController extends Controller
{
    public function __construct(
        protected TotpService $totp,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return ApiEnvelope::success($this->totp->status($request->user()));
    }

    public function begin(Request $request): JsonResponse
    {
        $payload = $this->totp->beginEnrollment($request->user());

        return ApiEnvelope::success($payload);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $payload = $this->totp->confirmEnrollment($request->user(), $validated['code']);

        return ApiEnvelope::success($payload);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'recovery' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['recovery'])) {
            $this->totp->recover($request->user(), $validated['code']);
        } else {
            $this->totp->verify($request->user(), $validated['code']);
        }

        return ApiEnvelope::success(['verified' => true]);
    }

    public function recover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $this->totp->recover($request->user(), $validated['code']);

        return ApiEnvelope::success(['verified' => true]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'recovery' => ['nullable', 'boolean'],
        ]);

        $this->totp->disable($request->user(), $validated['code'], (bool) ($validated['recovery'] ?? false));

        return ApiEnvelope::success(['enabled' => false]);
    }
}
