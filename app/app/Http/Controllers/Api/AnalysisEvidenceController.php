<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisEvidence;
use App\Services\Analytics\AccountTaxReportService;
use App\Services\Analytics\PortfolioPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalysisEvidenceController extends Controller
{
    public function __construct(
        private PortfolioPerformanceService $performance,
        private AccountTaxReportService $tax,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'calculation_type' => ['required', Rule::in(['portfolio_performance', 'account_tax'])],
            'from' => ['required_if:calculation_type,portfolio_performance', 'nullable', 'date', 'before:to'],
            'to' => ['required_if:calculation_type,portfolio_performance', 'nullable', 'date', 'after:from', 'before_or_equal:today'],
            'financial_year' => ['required_if:calculation_type,account_tax', 'nullable', 'regex:/^\\d{4}-\\d{2}$/'],
            'portfolio_ids' => ['sometimes', 'array'],
            'portfolio_ids.*' => ['integer', 'distinct'],
        ]);

        if ($validated['calculation_type'] === 'portfolio_performance') {
            $profile = \activePortfolio();
            $result = $this->performance->calculate($profile, $validated['from'], $validated['to']);
            $periodStart = $validated['from'];
            $periodEnd = $validated['to'];
            $mode = 'configured';
            $profileId = $profile->id;
            $assumptions = [
                'annualization_days' => $result['annualization_days'],
                'annual_risk_free_rate' => $result['annual_risk_free_rate'],
                'benchmark' => $result['benchmark']['stable_key'],
                ...$result['evidence'],
            ];
        } else {
            $portfolioIds = array_key_exists('portfolio_ids', $validated) ? $validated['portfolio_ids'] : null;
            $result = $this->tax->calculate($request->user(), $validated['financial_year'], $portfolioIds);
            $periodStart = $result['period']['from'];
            $periodEnd = $result['period']['to'];
            $mode = $result['calculation_mode'];
            $profileId = null;
            $assumptions = $result['assumptions'];
        }

        $evidence = AnalysisEvidence::query()->create([
            'user_id' => $request->user()->id,
            'profile_id' => $profileId,
            'calculation_type' => $validated['calculation_type'],
            'calculation_mode' => $mode,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'request_cutoff_at' => $result['request_cutoff_at'],
            'completeness' => $result['completeness'],
            'assumptions' => $assumptions,
            'inputs_digest' => [
                'sha256' => hash('sha256', json_encode([
                    'user_id' => $request->user()->id,
                    'profile_id' => $profileId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'request_cutoff_at' => $result['request_cutoff_at'],
                    'mode' => $mode,
                ], JSON_THROW_ON_ERROR)),
            ],
            'result' => $result,
        ]);

        return response()->json(['data' => $evidence], 201);
    }

    public function show(Request $request, string $evidence): JsonResponse
    {
        $row = AnalysisEvidence::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($evidence)
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }
}
