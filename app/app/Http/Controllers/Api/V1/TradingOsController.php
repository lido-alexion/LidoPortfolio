<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Data\DataEngine;
use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Execution\ExecutionEngine;
use App\Engines\Notification\NotificationEngine;
use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Review\ReviewEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\StockResolverService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TradingOsController extends Controller
{
    public function __construct(
        protected DataEngine $data,
        protected DiscoveryEngine $discovery,
        protected EvaluationEngine $evaluation,
        protected RecommendationEngine $recommendation,
        protected NotificationEngine $notification,
        protected ExecutionEngine $execution,
        protected ReviewEngine $review,
        protected DailyDecisionPipeline $pipeline,
        protected StockResolverService $stocks,
    ) {}

    public function securities(Request $request): JsonResponse
    {
        $page = $this->data->listSecurities(
            $request->query('search'),
            (int) $request->query('pageSize', $request->query('per_page', 50)),
        );

        return ApiEnvelope::success(
            $page->items(),
            [
                'page' => $page->currentPage(),
                'pageSize' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function securityShow(int $id): JsonResponse
    {
        $stock = $this->data->securityDetails($id);
        if (! $stock) {
            return ApiEnvelope::error('NOT_FOUND', 'Security not found.', 404);
        }

        return ApiEnvelope::success($stock);
    }

    public function priceBars(Request $request): JsonResponse
    {
        $securityId = (int) $request->query('security_id', $request->query('securityId', 0));
        if ($securityId <= 0) {
            return ApiEnvelope::error('VALIDATION_ERROR', 'security_id is required.', 422);
        }

        $page = $this->data->queryPriceBars(
            $securityId,
            $request->query('from'),
            $request->query('to'),
            (int) $request->query('pageSize', 100),
        );

        return ApiEnvelope::success($page->items(), [
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function importsStore(Request $request): JsonResponse
    {
        $force = (bool) $request->boolean('force');
        $result = $this->data->triggerImport($force);

        return ApiEnvelope::success($result, [], $result['accepted'] ? 202 : 200);
    }

    public function importsShow(string $id): JsonResponse
    {
        $history = $this->data->importHistory(50);
        $match = collect($history)->firstWhere('id', $id);
        if (! $match) {
            return ApiEnvelope::error('NOT_FOUND', 'Import job not found.', 404);
        }

        return ApiEnvelope::success($match);
    }

    public function datasetStatus(): JsonResponse
    {
        return ApiEnvelope::success($this->data->datasetStatus());
    }

    public function discoveryRunsStore(): JsonResponse
    {
        $profile = \activePortfolio();
        $result = $this->discovery->run($profile);

        return ApiEnvelope::success([
            'run' => $result['run'],
            'candidates' => $result['candidates'],
        ], [], 201);
    }

    public function candidates(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $runId = $request->query('discovery_run_id') ? (int) $request->query('discovery_run_id') : null;
        $items = $this->discovery->listCandidates(
            $runId,
            $profile,
            $request->query('source'),
            $request->query('search'),
        );

        return ApiEnvelope::success(array_map(fn ($c) => $this->serializeCandidate($c), $items));
    }

    public function evaluationRunsStore(): JsonResponse
    {
        $profile = \activePortfolio();

        try {
            $result = $this->evaluation->run($profile);
        } catch (\RuntimeException $e) {
            return ApiEnvelope::error('EVALUATION_PRECONDITION', $e->getMessage(), 422);
        }

        $run = $result['run'];

        return ApiEnvelope::success([
            'run' => [
                'id' => $run->id,
                'profile_id' => $run->profile_id,
                'discovery_run_id' => $run->discovery_run_id,
                'status' => $run->status,
                'stats' => $run->stats_json,
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'completed_at' => optional($run->completed_at)?->toIso8601String(),
            ],
            'results' => array_map(fn ($r) => $this->serializeEvaluation($r), $result['results']),
        ], [], 201);
    }

    public function evaluations(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $runId = $request->query('evaluation_run_id') ? (int) $request->query('evaluation_run_id') : null;
        $items = $this->evaluation->listResults($runId, $profile);

        return ApiEnvelope::success(array_map(fn ($r) => $this->serializeEvaluation($r), $items));
    }

    public function recommendationsGenerate(): JsonResponse
    {
        $profile = \activePortfolio();
        $result = $this->recommendation->generate($profile);

        if (config('trading_os.notification.notify_on_generate')) {
            $this->notification->notifyRecommendations($profile, $result['recommendations']);
        }

        return ApiEnvelope::success([
            'batch_id' => $result['batch_id'],
            'recommendations' => array_map(fn ($r) => $this->serializeRecommendation($r), $result['recommendations']),
        ], [], 201);
    }

    public function recommendationsIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $statusParam = $request->query('status');
        $statuses = null;
        if (is_string($statusParam) && trim($statusParam) !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $statusParam))));
        } elseif ($request->boolean('open', true) && ! $request->has('status') && ! $request->boolean('all')) {
            $statuses = [
                \App\Models\TradingRecommendation::STATUS_PENDING_REVIEW,
                \App\Models\TradingRecommendation::STATUS_DEFERRED,
                \App\Models\TradingRecommendation::STATUS_PENDING_EXECUTION,
                \App\Models\TradingRecommendation::STATUS_ACCEPTED,
                \App\Models\TradingRecommendation::STATUS_PUBLISHED,
            ];
        }

        $items = $this->recommendation->listForProfile($profile, $statuses);

        return ApiEnvelope::success(array_map(fn ($r) => $this->serializeRecommendation($r), $items));
    }

    public function recommendationsPendingExecution(): JsonResponse
    {
        $profile = \activePortfolio();
        $items = $this->recommendation->listPendingExecution($profile);
        $cash = app(\App\Services\CashManagementService::class)->summary($profile);

        return ApiEnvelope::success(
            array_map(fn ($r) => $this->serializeRecommendation($r, true), $items),
            ['cash' => $cash],
        );
    }

    public function recommendationsShow(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        return ApiEnvelope::success($this->serializeRecommendation($rec, true));
    }

    public function recommendationsReview(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'decision' => 'required|in:approved,accepted,rejected,deferred,APPROVED,ACCEPTED,REJECTED,DEFERRED',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->recordReview(
                $profile,
                $request->user(),
                $rec,
                strtolower($validated['decision']),
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success($this->serializeRecommendation($updated, true));
    }

    public function recommendationsReopen(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->reopenForReview(
                $profile,
                $request->user(),
                $rec,
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success($this->serializeRecommendation($updated, true));
    }

    public function recommendationsCancelExecution(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|in:'.implode(',', \App\Models\TradingRecommendation::CANCELLATION_REASONS),
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->cancelExecution(
                $profile,
                $request->user(),
                $rec,
                $validated['reason'] ?? 'other',
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success($this->serializeRecommendation($updated, true));
    }

    public function recommendationsExpire(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = $this->recommendation->markExpired(
                $profile,
                $request->user(),
                $rec,
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success($this->serializeRecommendation($updated, true));
    }

    public function recommendationsReviewHistory(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = $this->recommendation->findForProfile($profile, $id);
        if (! $rec) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        $items = $this->recommendation->reviewHistory($rec);

        return ApiEnvelope::success(array_map(fn ($r) => [
            'id' => $r->id,
            'decision' => $r->decision,
            'notes' => $r->notes,
            'user_id' => $r->user_id,
            'user' => $r->user?->name ?? $r->user?->email,
            'created_at' => optional($r->created_at)?->toIso8601String(),
        ], $items));
    }

    public function notificationsIndex(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->notification->history($profile));
    }

    public function notificationsRetry(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $n = $this->notification->retry($profile, $id);
        if (! $n) {
            return ApiEnvelope::error('NOT_FOUND', 'Notification not found.', 404);
        }

        return ApiEnvelope::success($n);
    }

    public function ordersStore(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'side' => 'required|in:buy,sell,BUY,SELL',
            'quantity' => 'required|numeric|gt:0',
            'price' => 'nullable|numeric|min:0',
            'fees' => 'nullable|numeric|min:0',
            'transaction_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'recommendation_id' => 'nullable|integer',
            'security_id' => 'nullable|integer',
            'stock_id' => 'nullable|integer',
            'symbol' => 'nullable|string',
            'execute_now' => 'nullable|boolean',
            'limit_price' => 'nullable|numeric|min:0',
        ]);

        if (! empty($validated['security_id']) && empty($validated['stock_id'])) {
            $request->merge(['stock_id' => $validated['security_id']]);
        }

        $executeNow = array_key_exists('execute_now', $validated)
            ? (bool) $validated['execute_now']
            : false;

        if ($executeNow && (! isset($validated['price']) || $validated['price'] === null)) {
            return ApiEnvelope::error('VALIDATION_ERROR', 'price is required when execute_now is true.', 422);
        }

        try {
            $stock = $this->stocks->resolve($request, allowCreate: false);
        } catch (\Throwable $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        try {
            $result = $this->execution->recordOrder($profile, $stock, [
                ...$validated,
                'side' => strtolower($validated['side']),
                'execute_now' => $executeNow,
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success([
            'order' => $result['order'],
            'transaction' => $result['transaction'],
            'position' => $result['position'],
        ], [], 201);
    }

    public function ordersIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $status = $request->query('status');

        return ApiEnvelope::success($this->execution->listOrders(
            $profile,
            50,
            is_string($status) && $status !== '' ? $status : null,
        ));
    }

    public function ordersExecute(Request $request, int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $order = $this->execution->findOrder($profile, $id);
        if (! $order) {
            return ApiEnvelope::error('NOT_FOUND', 'Order not found.', 404);
        }

        $validated = $request->validate([
            'price' => 'required|numeric|gt:0',
            'fees' => 'nullable|numeric|min:0',
            'transaction_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $result = $this->execution->executeOrder($profile, $order, $validated);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success([
            'order' => $result['order'],
            'transaction' => $result['transaction'],
            'position' => $result['position'],
        ]);
    }

    public function ordersCancel(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $order = $this->execution->findOrder($profile, $id);
        if (! $order) {
            return ApiEnvelope::error('NOT_FOUND', 'Order not found.', 404);
        }

        try {
            $cancelled = $this->execution->cancelOrder($profile, $order);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

            return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
        }

        return ApiEnvelope::success($cancelled);
    }

    public function transactionsIndex(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->execution->listTransactions($profile));
    }

    public function positionsIndex(): JsonResponse
    {
        $profile = \activePortfolio();
        $positions = $this->execution->listPositions($profile);

        return ApiEnvelope::success(array_map(function ($h) {
            return [
                'id' => $h->id,
                'security_id' => $h->stock_id,
                'symbol' => $h->stock?->symbol,
                'quantity' => (float) $h->quantity,
                'average_cost' => (float) $h->avg_buy_price,
                'invested_amount' => (float) $h->invested_amount,
                'status' => ((float) $h->quantity) > 0 ? 'open' : 'closed',
            ];
        }, $positions));
    }

    public function reviewsGenerate(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $start = $request->query('period_start')
            ? Carbon::parse($request->query('period_start'))->startOfDay()
            : null;
        $end = $request->query('period_end')
            ? Carbon::parse($request->query('period_end'))->startOfDay()
            : null;

        $result = $this->review->generate($profile, $start, $end);

        return ApiEnvelope::success([
            'report' => $result['report'],
            'metrics' => $result['metrics'],
        ], [], 201);
    }

    public function reviewsIndex(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->review->listReports($profile));
    }

    public function reviewsShow(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $report = $this->review->findReport($profile, $id);
        if (! $report) {
            return ApiEnvelope::error('NOT_FOUND', 'Review report not found.', 404);
        }

        return ApiEnvelope::success($report);
    }

    public function reviewDashboard(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->review->dashboard($profile));
    }

    public function reviewOutcomes(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->review->recommendationOutcomes($profile));
    }

    public function pipelineRun(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $result = $this->pipeline->run($profile, [
            'notify' => $request->boolean('notify', true),
            'review' => $request->boolean('review', true),
        ]);

        return ApiEnvelope::success([
            'pipeline_run' => $result['pipeline_run'],
            'stages' => $result['stages'],
        ], [], 201);
    }

    protected function serializeCandidate($c): array
    {
        $signals = $c->evidence['signals'] ?? [];
        $reason = $c->source;
        if (is_array($signals) && $signals !== []) {
            $labels = array_values(array_filter(array_map(
                fn ($s) => $s['label'] ?? $s['id'] ?? null,
                $signals,
            )));
            if ($labels !== []) {
                $reason = implode(', ', array_slice($labels, 0, 4));
            }
        }

        return [
            'id' => $c->id,
            'discovery_run_id' => $c->discovery_run_id,
            'security_id' => $c->security_id,
            'symbol' => $c->security?->symbol,
            'name' => $c->security?->name,
            'source' => $c->source,
            'discovery_reason' => $reason,
            'evidence' => $c->evidence,
            'evaluation_result_id' => $c->evaluationResult?->id,
            'created_at' => optional($c->created_at)?->toIso8601String(),
        ];
    }

    protected function serializeEvaluation($r): array
    {
        $indicators = $r->evidence['indicators'] ?? [];
        $components = $r->evidence['component_scores'] ?? [];
        $explanationParts = [];
        if ($r->passed_rules) {
            $explanationParts[] = 'Passed: '.implode(', ', array_slice($r->passed_rules, 0, 6));
        }
        if ($r->failed_rules) {
            $explanationParts[] = 'Failed: '.implode(', ', array_slice($r->failed_rules, 0, 6));
        }

        return [
            'id' => $r->id,
            'evaluation_run_id' => $r->evaluation_run_id,
            'candidate_id' => $r->candidate_id,
            'security_id' => $r->candidate?->security_id,
            'symbol' => $r->candidate?->security?->symbol,
            'name' => $r->candidate?->security?->name,
            'score' => (float) $r->score,
            'confidence' => (float) $r->confidence,
            'rank' => $r->rank,
            'evidence' => $r->evidence,
            'indicators' => $indicators,
            'component_scores' => $components,
            'passed_rules' => $r->passed_rules,
            'failed_rules' => $r->failed_rules,
            'explanation' => implode(' · ', $explanationParts),
        ];
    }

    protected function serializeRecommendation($r, bool $detailed = false): array
    {
        $payload = [
            'id' => $r->id,
            'security_id' => $r->security_id,
            'symbol' => $r->security?->symbol,
            'name' => $r->security?->name,
            'recommendation_type' => $r->recommendation_type,
            'portfolio_action' => method_exists($r, 'portfolioAction') ? $r->portfolioAction() : $r->recommendation_type,
            'ui_label' => method_exists($r, 'uiLabel') ? $r->uiLabel() : $r->recommendation_type,
            'market_opinion' => $r->market_opinion,
            'execution_plan' => $r->execution_plan,
            'current_allocation_pct' => $r->current_allocation_pct !== null ? (float) $r->current_allocation_pct : null,
            'target_allocation_pct' => $r->target_allocation_pct !== null ? (float) $r->target_allocation_pct : null,
            'suggested_allocation_pct' => $r->suggested_allocation_pct !== null ? (float) $r->suggested_allocation_pct : null,
            'reasoning' => $r->reasoning,
            'priority' => $r->priority,
            'confidence' => (float) $r->confidence,
            'score' => $r->evidence['score'] ?? ($r->market_opinion['score'] ?? null),
            'risk_level' => $r->risk_level,
            'suggested_position_size' => $r->suggested_position_size !== null ? (float) $r->suggested_position_size : null,
            'suggested_allocation_amount' => $r->suggested_allocation_amount !== null ? (float) $r->suggested_allocation_amount : null,
            'suggested_quantity' => method_exists($r, 'suggestedQuantity') ? $r->suggestedQuantity() : null,
            'suggested_investment_amount' => method_exists($r, 'suggestedInvestmentAmount') ? $r->suggestedInvestmentAmount() : null,
            'reserved_amount' => $r->reserved_amount !== null ? (float) $r->reserved_amount : null,
            'reservation_status' => $r->reservation_status,
            'reserved_at' => optional($r->reserved_at)?->toIso8601String(),
            'cash_balance_at_generation' => $r->cash_balance_at_generation !== null ? (float) $r->cash_balance_at_generation : null,
            'reserved_cash_at_generation' => $r->reserved_cash_at_generation !== null ? (float) $r->reserved_cash_at_generation : null,
            'available_cash_at_generation' => $r->available_cash_at_generation !== null ? (float) $r->available_cash_at_generation : null,
            'executed_amount' => $r->executed_amount !== null ? (float) $r->executed_amount : null,
            'reference_price' => $r->reference_price !== null ? (float) $r->reference_price : null,
            'current_market_price' => $this->latestCloseForSecurity($r->security_id),
            'status' => $r->status,
            'lifecycle_status' => $r->status,
            'review_status' => method_exists($r, 'reviewStatusLabel') ? $r->reviewStatusLabel() : $r->status,
            'execution_status' => method_exists($r, 'executionStatusLabel') ? $r->executionStatusLabel() : null,
            'category' => method_exists($r, 'category') ? $r->category() : 'actionable',
            'order_side' => method_exists($r, 'orderSide') ? $r->orderSide() : null,
            'evidence' => $r->evidence,
            'failed_checks' => $r->failed_checks,
            'expires_at' => optional($r->expires_at)?->toIso8601String(),
            'generated_at' => optional($r->generated_at)?->toIso8601String(),
            'approved_at' => optional($r->approved_at)?->toIso8601String(),
            'cancelled_at' => optional($r->cancelled_at)?->toIso8601String(),
            'cancellation_reason' => $r->cancellation_reason,
            'cancellation_reason_label' => $r->cancellation_reason
                ? (\App\Models\TradingRecommendation::CANCELLATION_REASON_LABELS[$r->cancellation_reason] ?? $r->cancellation_reason)
                : null,
            'executed_at' => optional($r->executed_at)?->toIso8601String(),
            'executed_transaction_id' => $r->executed_transaction_id,
            'recommendation_age_days' => $r->generated_at
                ? (int) $r->generated_at->diffInDays(now())
                : null,
            'evaluation_result_id' => $r->evaluation_result_id,
            'can_review' => method_exists($r, 'canBeReviewed') ? $r->canBeReviewed() : false,
            'can_reopen' => method_exists($r, 'canReopenForReview') ? $r->canReopenForReview() : false,
            'can_execute_manually' => method_exists($r, 'canExecuteManually') ? $r->canExecuteManually() : false,
            'can_cancel_execution' => method_exists($r, 'canCancelExecution') ? $r->canCancelExecution() : false,
            'can_create_order' => method_exists($r, 'canCreateOrder') ? $r->canCreateOrder() : false,
        ];

        if ($detailed) {
            $payload['reviews'] = $r->relationLoaded('reviews')
                ? $r->reviews->map(fn ($rev) => [
                    'id' => $rev->id,
                    'decision' => $rev->decision,
                    'notes' => $rev->notes,
                    'user' => $rev->user?->name ?? $rev->user?->email,
                    'created_at' => optional($rev->created_at)?->toIso8601String(),
                ])->all()
                : [];
            $payload['orders'] = $r->relationLoaded('orders')
                ? $r->orders->map(fn ($o) => [
                    'id' => $o->id,
                    'status' => $o->status,
                    'side' => $o->side,
                    'quantity' => (float) $o->quantity,
                ])->all()
                : [];
            if ($r->relationLoaded('executedTransaction') && $r->executedTransaction) {
                $tx = $r->executedTransaction;
                $payload['execution'] = [
                    'transaction_id' => $tx->id,
                    'transaction_date' => optional($tx->transaction_date)?->toDateString(),
                    'quantity' => (float) $tx->quantity,
                    'price' => (float) $tx->price,
                    'fees' => (float) $tx->fees,
                    'type' => $tx->type,
                    'source' => $tx->source,
                ];
            } else {
                $payload['execution'] = null;
            }
        }

        return $payload;
    }

    protected function latestCloseForSecurity(?int $securityId): ?float
    {
        if (! $securityId) {
            return null;
        }

        $close = \App\Models\StockPrice::query()
            ->where('stock_id', $securityId)
            ->orderByDesc('price_date')
            ->value('close_price');

        return $close !== null ? (float) $close : null;
    }
}
