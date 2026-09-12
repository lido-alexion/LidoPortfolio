<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalUpdateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundamentalDataController extends Controller
{
    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalUpdateService $updates,
    ) {}

    public function show(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'basis' => ['nullable', 'in:quarterly,annual,ttm'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : now();
        $basis = (string) ($validated['basis'] ?? 'ttm');
        $price = isset($validated['price']) ? (float) $validated['price'] : null;
        $metricKeys = ['revenue', 'net_income', 'roe', 'debt_equity', 'net_debt', 'free_cash_flow', 'pe', 'pb'];

        return ApiEnvelope::success([
            'stock' => ['id' => $stock->id, 'symbol' => $stock->symbol, 'name' => $stock->name],
            'as_of' => $asOf->toDateString(),
            'basis' => $basis,
            'metrics' => collect($metricKeys)
                ->map(fn (string $metric) => $this->fundamentals->metric($stock, $metric, $basis, $asOf, $price))
                ->values()
                ->all(),
            'statements' => [
                'quarterly' => $this->statementRows($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf),
                'annual' => $this->statementRows($stock, FundamentalDataService::CADENCE_ANNUAL, $asOf),
            ],
        ]);
    }

    public function adminStatus(): JsonResponse
    {
        return ApiEnvelope::success($this->updates->status());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quarterly_freshness_months' => ['required', 'integer', 'min:1', 'max:36'],
            'annual_freshness_months' => ['required', 'integer', 'min:1', 'max:60'],
            'request_delay_ms' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'paused' => ['nullable', 'boolean'],
        ]);

        $settings = $this->fundamentals->settings();
        $settings->update($validated);

        return ApiEnvelope::success($settings->fresh()->toArray());
    }

    public function startRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'string', 'max:64'],
            'stock_id' => ['nullable', 'integer', 'exists:portfolio_stocks,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'process_now' => ['nullable', 'boolean'],
        ]);

        $run = $this->updates->createRun(
            'manual',
            (string) ($validated['scope'] ?? 'incremental'),
            isset($validated['stock_id']) ? (int) $validated['stock_id'] : null,
            (int) ($validated['limit'] ?? 25),
        );

        $data = ['run' => $run->toArray()];
        if ($request->boolean('process_now')) {
            $data['processed'] = $this->updates->process($run, (int) ($validated['limit'] ?? 25));
        }

        return ApiEnvelope::success($data, [], 201);
    }

    public function processRun(Request $request, FundamentalUpdateRun $run): JsonResponse
    {
        $validated = $request->validate([
            'batch' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return ApiEnvelope::success($this->updates->process($run, (int) ($validated['batch'] ?? 25)));
    }

    private function statementRows(Stock $stock, string $cadence, Carbon $asOf): array
    {
        return collect($this->fundamentals->factMap($stock, $cadence, $asOf))
            ->values()
            ->sortByDesc(fn ($fact) => $fact->period_end?->toDateString() ?? '')
            ->map(fn ($fact) => [
                'statement_type' => $fact->statement_type,
                'fact_key' => $fact->fact_key,
                'value' => $fact->value !== null ? (float) $fact->value : null,
                'period_end' => $fact->period_end?->toDateString(),
                'availability_date' => $fact->availability_date?->toDateString(),
                'revision_number' => $fact->revision_number,
                'is_current' => $fact->is_current,
            ])
            ->values()
            ->all();
    }
}
