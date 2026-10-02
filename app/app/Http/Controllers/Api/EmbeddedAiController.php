<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInsightCache;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiToolFailure;
use App\Services\AI\EmbeddedAiService;
use App\Services\AI\StockInsightEvidence;
use App\Services\AI\StrategyDesignerInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmbeddedAiController extends Controller
{
    private function profile(Request $request): ?PortfolioProfile
    {
        abort_if($request->user()->is_admin, 403);
        $id = $request->header('X-Profile-Id') ?? $request->header('X-Portfolio-Id');
        $query = PortfolioProfile::query()->where('user_id', $request->user()->id);

        return $id ? $query->findOrFail($id) : $query->where('is_default', true)->first();
    }

    public function stock(Request $request, Stock $stock, StockInsightEvidence $evidence, EmbeddedAiService $service)
    {
        $request->validate(['refresh' => 'sometimes|boolean', 'lookup_only' => 'sometimes|boolean']);
        $context = $evidence->assemble($stock, $this->profile($request), $request->user());

        return response()->json(['data' => $service->execute('stock_analysis_insight', $context, $request->user()->id, $request->boolean('refresh'), $request->boolean('lookup_only'))]);
    }

    public function strategy(Request $request, EmbeddedAiService $service)
    {
        $this->profile($request);
        $data = $request->validate(['inputs' => 'required|array', 'refresh' => 'sometimes|boolean', 'lookup_only' => 'sometimes|boolean']);
        $input = StrategyDesignerInput::normalize($data['inputs']);
        // Authoring-contract changes also materially affect compatibility and cache identity.
        $input['authoring_contract_version'] = $service->authoringContractVersion();

        return response()->json(['data' => $service->execute('strategy_designer', ['scope' => 'account', 'user_id' => $request->user()->id, 'input' => $input], $request->user()->id, $request->boolean('refresh'), $request->boolean('lookup_only'))]);
    }

    public function draft(Request $request, AiAgentService $agent)
    {
        $this->profile($request);
        $data = $request->validate(['fingerprint' => 'required|string|size:64']);
        $cache = AiInsightCache::query()->where('capability_id', 'strategy_designer')->where('scope', 'account')->where('user_id', $request->user()->id)->where('fingerprint', $data['fingerprint'])->firstOrFail();
        try {
            $run = DB::transaction(function () use ($request, $agent, $cache) {
                [$run, $delegation] = $agent->create($request, 'Create a draft strategy from the reviewed Strategy Designer result.');

                return $agent->gateway(['run_id' => $run->id, 'delegation' => $delegation, 'operation' => 'preview', 'plan' => [['tool' => 'strategy.create', 'arguments' => ['envelope' => $cache->response['draft_envelope']], 'reason' => 'Convert the reviewed advisory design to a Library draft.']]]);
            });

            return response()->json(['data' => $run]);
        } catch (AiToolFailure $error) {
            return response()->json(['error' => ['code' => $error->reason]], $error->httpStatus);
        }
    }
}
