<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\FundamentalInvestorSnapshotService;
use App\Services\Fundamentals\AIInsightsService;
use App\Services\Fundamentals\FundamentalMetricCatalog;
use App\Services\Fundamentals\FundamentalSignalsService;
use App\Services\Fundamentals\FundamentalUpdateService;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FundamentalDataController extends Controller
{
    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalUpdateService $updates,
        protected FundamentalBootstrapService $bootstrap,
        protected FundamentalInvestorSnapshotService $investorSnapshot,
        protected FundamentalSignalsService $fundamentalSignals,
        protected AIInsightsService $aiInsights,
        protected FundamentalMetricCatalog $metricCatalog,
    ) {}

    public function metricCatalog(): JsonResponse
    {
        return ApiEnvelope::success($this->metricCatalog->toArray());
    }

    public function show(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'basis' => ['nullable', 'in:quarterly,annual,ttm'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'include_insights' => ['nullable', 'boolean'],
            'include_ai_insights' => ['nullable', 'boolean'],
        ]);
        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : now();
        $snapshot = $this->investorSnapshot->snapshot($stock, $asOf);
        if ($request->boolean('include_ai_insights')) {
            $snapshot['insights'] = $this->aiInsights->enrich($stock, $asOf, [], $request->user());
        } elseif ($request->boolean('include_insights')) {
            $snapshot['insights'] = $this->fundamentalSignals->deterministicInsights($stock, $asOf);
        }
        if (isset($validated['price'])) {
            $price = (float) $validated['price'];
            $basis = (string) ($validated['basis'] ?? 'ttm');
            $metricKeys = ['revenue', 'net_income', 'roe', 'debt_equity', 'net_debt', 'free_cash_flow', 'pe', 'pb'];
            $snapshot['metrics'] = collect($metricKeys)
                ->map(fn (string $metric) => $this->fundamentals->metric($stock, $metric, $basis, $asOf, $price))
                ->values()
                ->all();
            $snapshot['basis'] = $basis;
            $snapshot['market_price'] = $price;
        }

        app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_FUNDAMENTALS_VIEW, [
            'stock_id' => $stock->id,
            'include_insights' => $request->boolean('include_insights'),
            'include_ai_insights' => $request->boolean('include_ai_insights'),
        ]);
        if ($request->boolean('include_ai_insights')) {
            app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_FUNDAMENTALS_AI_INSIGHTS, [
                'stock_id' => $stock->id,
                'surface' => 'fundamentals_api',
            ]);
        }

        return ApiEnvelope::success($snapshot);
    }

    public function history(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'cadence' => ['required', 'in:quarterly,annual'],
            'as_of' => ['nullable', 'date'],
            'periods' => ['nullable', 'integer', 'min:2', 'max:16'],
        ]);
        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : now();
        $cadence = $validated['cadence'] === 'annual'
            ? FundamentalDataService::CADENCE_ANNUAL
            : FundamentalDataService::CADENCE_QUARTERLY;

        return ApiEnvelope::success($this->investorSnapshot->statementHistory(
            $stock,
            $cadence,
            $asOf,
            (int) ($validated['periods'] ?? 8),
        ));
    }

    public function metricHistory(Request $request, Stock $stock, string $metric): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'range' => ['nullable', 'in:1y,3y,5y,10y'],
            'frequency' => ['nullable', 'in:default,quarterly,daily,monthly'],
        ]);
        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : now();

        return ApiEnvelope::success($this->investorSnapshot->metricHistory(
            $stock,
            $metric,
            $asOf,
            (string) ($validated['range'] ?? '5y'),
            (string) ($validated['frequency'] ?? 'default'),
        ));
    }

    public function adminStatus(): JsonResponse
    {
        return ApiEnvelope::success(array_merge($this->updates->status(), [
            'bootstrap' => $this->bootstrap->status(),
            'ai_insights' => $this->aiInsights->adminDiagnostics(),
            'exchange_fallbacks' => $this->fundamentals->exchangeFallbackStatus(),
        ]));
    }

    public function bootstrapStatus(): JsonResponse
    {
        return ApiEnvelope::success($this->bootstrap->status());
    }

    public function testAiInsights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['nullable', 'integer', 'exists:portfolio_stocks,id'],
            'provider' => ['nullable', 'in:gemini,codex'],
        ]);

        $stock = isset($validated['stock_id'])
            ? Stock::query()->findOrFail((int) $validated['stock_id'])
            : Stock::query()->where('exchange', 'NSE')->orderBy('symbol')->first();

        if ($stock === null) {
            return ApiEnvelope::error('no_stock', 'No stock available for AI provider test.', 422);
        }

        return ApiEnvelope::success($this->aiInsights->runAdminProviderTest(
            $stock,
            $validated['provider'] ?? null,
        ));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quarterly_freshness_months' => ['required', 'integer', 'min:1', 'max:36'],
            'annual_freshness_months' => ['required', 'integer', 'min:1', 'max:60'],
            'request_delay_ms' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'paused' => ['nullable', 'boolean'],
            'ai_insights_primary_provider' => ['nullable', 'in:gemini,codex'],
            'nse_official_fallback_enabled' => ['nullable', 'boolean'],
            'bse_official_fallback_enabled' => ['nullable', 'boolean'],
        ]);

        $fallbacks = $this->fundamentals->exchangeFallbackStatus();
        foreach (['nse', 'bse'] as $exchange) {
            $field = $exchange.'_official_fallback_enabled';
            if (($validated[$field] ?? false) && ! $fallbacks[$exchange]['configured']) {
                throw ValidationException::withMessages([$field => strtoupper($exchange).' fallback cannot be enabled until an approved feed route is configured.']);
            }
        }

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
