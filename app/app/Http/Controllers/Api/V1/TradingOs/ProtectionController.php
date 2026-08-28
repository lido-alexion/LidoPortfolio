<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Protection\PositionProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProtectionController extends Controller
{
    public function __construct(
        protected PositionProtectionService $protections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $holdingId = $request->query('holding_id');
        $stockId = $request->query('stock_id');
        $rows = $this->protections->listForProfile(
            $profile,
            is_numeric($holdingId) ? (int) $holdingId : null,
            is_numeric($stockId) ? (int) $stockId : null,
        );

        return ApiEnvelope::success($rows->map(fn ($row) => $this->protections->present($row))->values()->all());
    }

    public function show(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $row = $this->protections->findForProfile($profile, $id);

        return ApiEnvelope::success($this->protections->present($row));
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'holding_id' => ['required', 'integer'],
            'type' => ['required', 'string', Rule::in(['target', 'stop'])],
            'totp' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $row = $this->protections->place(
            $request->user(),
            $profile,
            (int) $validated['holding_id'],
            $validated['type'],
            $validated['totp'] ?? null,
            $validated['recovery_code'] ?? null,
        );

        return ApiEnvelope::success($this->protections->present($row), [], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'totp' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $row = $this->protections->cancel(
            $request->user(),
            $profile,
            $id,
            $validated['totp'] ?? null,
            $validated['recovery_code'] ?? null,
        );

        return ApiEnvelope::success($this->protections->present($row));
    }

    public function reconcile(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $row = $this->protections->findForProfile($profile, $id);
        $row = $this->protections->reconcileOne($profile, $row);

        return ApiEnvelope::success($this->protections->present($row));
    }
}
