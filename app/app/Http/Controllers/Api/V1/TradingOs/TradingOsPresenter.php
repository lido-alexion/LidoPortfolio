<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Models\StockPrice;
use App\Models\TradingRecommendation;

/**
 * HTTP wire mapping for Trading OS list/detail JSON.
 * Field names and shapes must stay identical to the previous TradingOsController serializers.
 */
final class TradingOsPresenter
{
    public static function candidate($c): array
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

        $eval = $c->evaluationResult;
        $score = null;
        $confidence = null;
        $rank = null;
        $explanation = null;
        $indicators = null;
        $componentScores = null;
        $passedRules = null;
        $failedRules = null;
        if ($eval) {
            $serialized = self::evaluation($eval);
            $score = $serialized['score'];
            $confidence = $serialized['confidence'];
            $rank = $serialized['rank'];
            $explanation = $serialized['explanation'];
            $indicators = $serialized['indicators'];
            $componentScores = $serialized['component_scores'];
            $passedRules = $serialized['passed_rules'];
            $failedRules = $serialized['failed_rules'];
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
            'evaluation_result_id' => $eval?->id,
            'score' => $score,
            'confidence' => $confidence,
            'rank' => $rank,
            'explanation' => $explanation,
            'indicators' => $indicators,
            'component_scores' => $componentScores,
            'passed_rules' => $passedRules,
            'failed_rules' => $failedRules,
            'created_at' => optional($c->created_at)?->toIso8601String(),
        ];
    }

    public static function evaluation($r): array
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

    public static function evaluationRun($run): array
    {
        return [
            'id' => $run->id,
            'profile_id' => $run->profile_id,
            'discovery_run_id' => $run->discovery_run_id,
            'status' => $run->status,
            'stats' => $run->stats_json,
            'result_count' => isset($run->results_count) ? (int) $run->results_count : null,
            'error_message' => $run->error_message,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'completed_at' => optional($run->completed_at)?->toIso8601String(),
        ];
    }

    public static function recommendation($r, bool $detailed = false): array
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
            'score' => $r->strategy_score ?? ($r->evidence['score'] ?? ($r->market_opinion['score'] ?? null)),
            'strategy_score' => $r->strategy_score !== null ? (float) $r->strategy_score : ($r->evidence['strategy_score'] ?? null),
            'strategy_version_id' => $r->strategy_version_id,
            'strategy_id' => method_exists($r, 'owningStrategyId') ? $r->owningStrategyId() : null,
            'strategy_version' => $r->evidence['strategy_version'] ?? null,
            'strategy_name' => $r->evidence['strategy_name'] ?? null,
            'factor_breakdown' => $r->evidence['factor_breakdown'] ?? null,
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
            'current_market_price' => self::latestCloseForSecurity($r->security_id),
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
                ? (TradingRecommendation::CANCELLATION_REASON_LABELS[$r->cancellation_reason] ?? $r->cancellation_reason)
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
            'capital_allocation_status' => method_exists($r, 'capitalAllocationStatus') ? $r->capitalAllocationStatus() : null,
            'capital_request_id' => (static function () use ($r): ?int {
                $meta = method_exists($r, 'capitalAllocationMeta') ? $r->capitalAllocationMeta() : null;
                if (! is_array($meta) || ! isset($meta['capital_request_id'])) {
                    return null;
                }
                $id = (int) $meta['capital_request_id'];

                return $id > 0 ? $id : null;
            })(),
            'capital_target_amount' => method_exists($r, 'capitalTargetAmount') ? $r->capitalTargetAmount() : null,
            // OD-12 position target (full conviction) vs this-cycle staggered requirement.
            'position_target_amount' => is_numeric($r->execution_plan['position_target_amount'] ?? null)
                ? (float) $r->execution_plan['position_target_amount']
                : (is_numeric($r->evidence['capital_allocation']['position_target_amount'] ?? null)
                    ? (float) $r->evidence['capital_allocation']['position_target_amount']
                    : null),
            'this_cycle_amount' => is_numeric($r->execution_plan['this_cycle_amount'] ?? null)
                ? (float) $r->execution_plan['this_cycle_amount']
                : null,
            'position_filled_amount' => is_numeric($r->execution_plan['filled_amount'] ?? null)
                ? (float) $r->execution_plan['filled_amount']
                : null,
            'remaining_target_amount' => is_numeric($r->execution_plan['remaining_amount'] ?? null)
                ? (float) $r->execution_plan['remaining_amount']
                : null,
            'is_first_entry' => array_key_exists('is_first_entry', is_array($r->execution_plan) ? $r->execution_plan : [])
                ? (bool) $r->execution_plan['is_first_entry']
                : null,
            'primary_exit_reason' => method_exists($r, 'primaryExitReason') ? $r->primaryExitReason() : null,
            'exit_attribution' => is_array($r->evidence['exit_attribution'] ?? null)
                ? $r->evidence['exit_attribution']
                : (is_array($r->execution_plan['exit_attribution'] ?? null) ? $r->execution_plan['exit_attribution'] : null),
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
                    'exit_reason' => $tx->exit_reason ?? null,
                ];
            } else {
                $payload['execution'] = null;
            }

            try {
                $profile = \activePortfolio();
                if ($profile) {
                    $payload['capital_resolution'] = app(\App\Services\Lending\CapitalResolutionStatusService::class)
                        ->forRecommendation($profile, $r);
                }
            } catch (\Throwable) {
                $payload['capital_resolution'] = null;
            }
        }

        return $payload;
    }

    public static function position($h): array
    {
        return [
            'id' => $h->id,
            'security_id' => $h->stock_id,
            'symbol' => $h->stock?->symbol,
            'quantity' => (float) $h->quantity,
            'average_cost' => (float) $h->avg_buy_price,
            'invested_amount' => (float) $h->invested_amount,
            'status' => ((float) $h->quantity) > 0 ? 'open' : 'closed',
        ];
    }

    public static function reviewHistoryItem($r): array
    {
        return [
            'id' => $r->id,
            'decision' => $r->decision,
            'notes' => $r->notes,
            'user_id' => $r->user_id,
            'user' => $r->user?->name ?? $r->user?->email,
            'created_at' => optional($r->created_at)?->toIso8601String(),
        ];
    }

    public static function latestCloseForSecurity(?int $securityId): ?float
    {
        if (! $securityId) {
            return null;
        }

        $close = StockPrice::query()
            ->where('stock_id', $securityId)
            ->orderByDesc('price_date')
            ->value('close_price');

        return $close !== null ? (float) $close : null;
    }
}
