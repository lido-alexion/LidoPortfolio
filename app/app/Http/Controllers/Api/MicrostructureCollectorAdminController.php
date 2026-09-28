<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Microstructure\MicrostructureCollectorControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicrostructureCollectorAdminController extends Controller
{
    public function __construct(
        protected MicrostructureCollectorControlService $collector,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->collector->operationalStatus()]);
    }

    public function command(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:64'],
        ]);

        $state = $this->collector->applyAdminCommand($request->user(), $validated['command']);

        return response()->json([
            'data' => $this->collector->operationalStatus(),
            'message' => 'Collector command queued.',
            'state' => [
                'manual_hold' => $state->manual_hold,
                'last_command' => $state->last_command,
                'last_command_at' => $state->last_command_at?->toIso8601String(),
            ],
        ]);
    }
}
