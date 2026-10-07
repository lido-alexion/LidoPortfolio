<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\AIInsightsService;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalInvestorSnapshotService;
use App\Services\Fundamentals\FundamentalMetricCatalog;
use App\Services\Fundamentals\ManualFundamentalSpreadsheetImporter;
use App\Services\Fundamentals\FundamentalSignalsService;
use App\Services\Fundamentals\FundamentalUpdateService;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        protected ManualFundamentalSpreadsheetImporter $manualSpreadsheetImporter,
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

    public function importManualWorkbook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_symbol' => ['required', 'string', 'max:32'],
            'exchange' => ['required', 'string', 'in:NSE,NSE+,BSE'],
            'statement_basis' => ['required', 'in:standalone,consolidated'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'confirm_company' => ['accepted'],
        ]);

        $stock = Stock::query()
            ->whereRaw('UPPER(symbol) = ?', [strtoupper(trim($validated['stock_symbol']))])
            ->whereRaw('UPPER(exchange) = ?', [strtoupper($validated['exchange'])])
            ->where('is_active', true)
            ->where('is_benchmark', false)
            ->first();

        if ($stock === null) {
            throw ValidationException::withMessages([
                'stock_symbol' => 'No active company stock matches that symbol and exchange.',
            ]);
        }

        $uploadedFile = $validated['file'];
        $result = $this->manualSpreadsheetImporter->import(
            $uploadedFile->getRealPath(),
            $stock,
            (int) $request->user()->id,
            $uploadedFile->getClientOriginalName(),
            $validated['statement_basis'],
            $this->fundamentals,
        );

        return ApiEnvelope::success([
            'stock' => ['id' => $stock->id, 'symbol' => $stock->symbol, 'exchange' => $stock->exchange],
            'import' => $result,
        ]);
    }

    public function manualFetch(Request $request, Stock $stock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless($stock->isEffectivelyActive() && ! $stock->is_benchmark, 422, 'Only active company stocks can be fetched.');

        $exchange = strtoupper((string) $stock->exchange);
        if (! in_array($exchange, ['NSE', 'NSE+', 'BSE'], true)) {
            return ApiEnvelope::error('unsupported_exchange', 'Manual exchange fetching is unavailable for this stock.', 422);
        }
        $fallbacks = $this->fundamentals->exchangeFallbackStatus();
        $key = strtolower($exchange === 'NSE+' ? 'nse' : $exchange);
        if (! ($fallbacks[$key]['active'] ?? false)) {
            return ApiEnvelope::error('exchange_fallback_disabled', 'The approved exchange feed is not enabled yet.', 409);
        }
        if (DB::table('stox_fundamental_facts')->where('stock_id', $stock->id)->exists()) {
            return ApiEnvelope::error('fundamentals_already_present', 'Fundamental data already exists for this stock.', 409);
        }

        $userLimitKey = 'fundamentals-manual-fetch:user:'.$user->id;
        if (RateLimiter::tooManyAttempts($userLimitKey, 3)) {
            return ApiEnvelope::error('manual_fetch_rate_limited', 'Please wait before requesting another stock.', 429);
        }
        $stockLock = Cache::lock('fundamentals-manual-fetch:stock:'.$stock->id, 300);
        if (! $stockLock->get()) {
            return ApiEnvelope::error('manual_fetch_in_progress', 'A fetch for this stock is already in progress.', 409);
        }

        RateLimiter::hit($userLimitKey, 3600);
        try {
            $run = $this->updates->createRun('manual', 'manual_stock', (int) $stock->id, 1);
            $result = $this->updates->process($run, 2);
            if (in_array(($result['status'] ?? null), ['queued', 'running'], true)) {
                return ApiEnvelope::error('manual_fetch_busy', 'Another fundamentals update is running. Try again shortly.', 409);
            }
            if (($result['status'] ?? null) === 'completed_with_errors' || ! empty($result['last_error'])) {
                return ApiEnvelope::error('manual_fetch_incomplete', 'The exchange temporarily deferred this fetch. Please try again after its cooldown.', 503);
            }

            return ApiEnvelope::success(['run' => $result]);
        } finally {
            $stockLock->release();
        }
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
            'nse_official_feed_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'bse_official_feed_url' => ['nullable', 'string', 'max:2048', 'url:https'],
        ]);

        $this->validateApprovedFeedUrls($validated);
        $fallbacks = $this->fundamentals->exchangeFallbackStatus($validated);
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

    /**
     * Accept only HTTPS feed URLs on an approved public hostname. Credentials and query
     * parameters are forbidden so secrets cannot be stored in or leaked from route URLs.
     *
     * @param  array<string,mixed>  $validated
     */
    private function validateApprovedFeedUrls(array $validated): void
    {
        $allowedHosts = array_values(array_filter(array_map(
            static fn ($host) => strtolower(rtrim(trim((string) $host), '.')),
            (array) config('fundamentals_bootstrap.approved_feed_hosts', []),
        )));

        foreach (['nse', 'bse'] as $exchange) {
            $field = $exchange.'_official_feed_url';
            $url = trim((string) ($validated[$field] ?? ''));
            if ($url === '') {
                continue;
            }

            $parts = parse_url($url);
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            $safeHost = $host !== ''
                && filter_var($host, FILTER_VALIDATE_IP) === false
                && ! in_array($host, ['localhost'], true)
                && ! str_ends_with($host, '.local')
                && collect($allowedHosts)->contains(
                    static fn (string $allowed): bool => $host === $allowed || str_ends_with($host, '.'.$allowed),
                );

            if (($parts['scheme'] ?? null) !== 'https'
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || ! $safeHost) {
                throw ValidationException::withMessages([
                    $field => 'Use an HTTPS URL on an approved host, with no credentials, query string, or fragment.',
                ]);
            }
        }
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
