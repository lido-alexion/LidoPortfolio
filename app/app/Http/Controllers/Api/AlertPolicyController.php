<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertPolicy;
use App\Services\Alerts\AlertPolicyEvaluationService;
use App\Services\Alerts\AlertPolicyService;
use App\Support\ApiErrorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertPolicyController extends Controller
{
    public function __construct(
        protected AlertPolicyService $policies,
        protected AlertPolicyEvaluationService $evaluation,
    ) {}

    public function meta(): JsonResponse
    {
        return response()->json(['data' => $this->policies->meta()]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->policies->listForProfile(\activePortfolio()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $policy = $this->policies->create(\activePortfolio(), $request->all());

        return response()->json(['data' => $policy], 201);
    }

    public function show(AlertPolicy $alertPolicy): JsonResponse
    {
        return response()->json(['data' => $alertPolicy]);
    }

    public function update(Request $request, AlertPolicy $alertPolicy): JsonResponse
    {
        $policy = $this->policies->update($alertPolicy, $request->all());

        return response()->json(['data' => $policy]);
    }

    public function destroy(AlertPolicy $alertPolicy): JsonResponse
    {
        $this->policies->delete($alertPolicy);

        return response()->json(['message' => 'Alert policy deleted']);
    }

    public function evaluate(): JsonResponse
    {
        ApiErrorMessage::assertAlertPolicySchemaReady();

        $result = $this->evaluation->evaluateProfile(\activePortfolio());

        $message = $result['generated'] > 0
            ? "Generated {$result['generated']} alert(s)."
            : 'No new alerts generated.';

        if ($result['generated'] === 0 && ($result['holdings_checked'] ?? 0) > 0) {
            $message .= ' See evaluation log below for per-holding reasons.';
        }

        return response()->json([
            'message' => $message,
            'data' => $result,
        ]);
    }
}
