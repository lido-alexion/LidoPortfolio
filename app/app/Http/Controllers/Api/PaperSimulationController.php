<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaperSimulationEvent;
use App\Models\PortfolioProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaperSimulationController extends Controller
{
    public function show(PortfolioProfile $portfolio): JsonResponse
    {
        $this->assertPaper($portfolio);

        return response()->json(['data' => $this->present($portfolio)]);
    }

    public function pause(Request $request, PortfolioProfile $portfolio): JsonResponse
    {
        $this->assertPaper($portfolio);
        if ($portfolio->simulation_state !== PortfolioProfile::SIMULATION_PAUSED) {
            DB::transaction(function () use ($request, $portfolio): void {
                $portfolio->forceFill(['simulation_state' => PortfolioProfile::SIMULATION_PAUSED])->save();
                PaperSimulationEvent::query()->create([
                    'profile_id' => $portfolio->id,
                    'event_type' => 'investor_paused',
                    'effective_session_date' => now()->toDateString(),
                    'user_id' => $request->user()->id,
                    'evidence' => ['catch_up_on_resume' => false, 'processing_timestamp' => now()->toISOString()],
                ]);
            });
        }

        return response()->json(['data' => $this->present($portfolio->fresh())]);
    }

    public function resume(Request $request, PortfolioProfile $portfolio): JsonResponse
    {
        $this->assertPaper($portfolio);
        if ($portfolio->simulation_state === PortfolioProfile::SIMULATION_PAUSED) {
            DB::transaction(function () use ($request, $portfolio): void {
                $portfolio->forceFill([
                    'simulation_state' => PortfolioProfile::SIMULATION_ACTIVE,
                    // Deliberately paused sessions are never caught up.
                    'simulation_checkpoint_date' => now()->toDateString(),
                ])->save();
                PaperSimulationEvent::query()->create([
                    'profile_id' => $portfolio->id,
                    'event_type' => 'investor_resumed',
                    'effective_session_date' => now()->toDateString(),
                    'user_id' => $request->user()->id,
                    'evidence' => ['paused_sessions_skipped' => true, 'processing_timestamp' => now()->toISOString()],
                ]);
            });
        }

        return response()->json(['data' => $this->present($portfolio->fresh())]);
    }

    private function assertPaper(PortfolioProfile $portfolio): void
    {
        if (! $portfolio->isPaper()) {
            throw ValidationException::withMessages(['portfolio' => 'Simulation controls are available only for Paper portfolios.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(PortfolioProfile $portfolio): array
    {
        return [
            'portfolio_id' => $portfolio->id,
            'portfolio_type' => $portfolio->portfolio_type,
            'state' => $portfolio->simulation_state,
            'price_method' => $portfolio->simulation_price_method,
            'checkpoint_date' => $portfolio->simulation_checkpoint_date?->toDateString(),
            'manual_interventions_allowed' => $portfolio->simulation_state === PortfolioProfile::SIMULATION_ACTIVE,
            'events' => PaperSimulationEvent::query()->where('profile_id', $portfolio->id)
                ->orderByDesc('id')->limit(50)->get(),
        ];
    }
}
