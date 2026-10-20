<?php

namespace App\Services\Strategy;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\Holding;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use Illuminate\Support\Facades\DB;

/** Resolves archive blockers from existing account and strategy-owned records. */
final class StrategyArchiveEligibility
{
    /** @return list<array{code:string,label:string,count:int,href:string}> */
    public function blockers(PortfolioProfile $profile, TradingStrategy $strategy): array
    {
        $sid = (int) $strategy->id;
        $pid = (int) $profile->id;
        $ownedRecommendationIds = TradingRecommendation::query()
            ->where('profile_id', $pid)
            ->whereIn('strategy_version_id', DB::table('portfolio_tos_strategy_versions')
                ->select('id')->where('strategy_id', $sid));

        $holdings = Holding::query()->where('profile_id', $pid)
            ->where(function ($query) use ($sid) {
                $query->where('strategy_id', $sid)->orWhere('owner_key', Holding::ownerKeyFor($sid));
            })->where('quantity', '>', 0)->count();

        $actionable = (clone $ownedRecommendationIds)
            ->whereIn('recommendation_type', array_merge(TradingRecommendation::ACTIONABLE_ACTIONS, ['BUY', 'SELL']))
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
                TradingRecommendation::STATUS_ACTIVE_LEGACY,
            ])->count();

        $pendingExecutions = (clone $ownedRecommendationIds)
            ->whereIn('status', [TradingRecommendation::STATUS_PENDING_EXECUTION, TradingRecommendation::STATUS_ACCEPTED])
            ->count();

        $orders = TradingOrder::query()->where('profile_id', $pid)
            ->whereIn('status', [TradingOrder::STATUS_PENDING])
            ->whereIn('recommendation_id', (clone $ownedRecommendationIds)->select('id'))
            ->count();
        $inFlightBrokerOrders = TradingOrder::query()->where('profile_id', $pid)
            ->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)
            ->whereIn('recommendation_id', (clone $ownedRecommendationIds)->select('id'))
            ->count();

        $reserved = (clone $ownedRecommendationIds)
            ->where('reservation_status', TradingRecommendation::RESERVATION_RESERVED)
            ->where('reserved_amount', '>', 0)->count();

        $loans = CapitalLoan::query()->where('profile_id', $pid)
            ->where(function ($query) use ($sid) {
                $query->where('borrower_strategy_id', $sid)->orWhere('lender_strategy_id', $sid);
            })->where('outstanding', '>', 0)->count();
        $bridgeLoans = RecallBridgeLoan::query()->where('profile_id', $pid)
            ->where(function ($query) use ($sid) {
                $query->where('borrower_strategy_id', $sid)->orWhere('lender_strategy_id', $sid);
            })->where('outstanding', '>', 0)->count();
        $recalls = CapitalRecall::query()->where('profile_id', $pid)
            ->where(function ($query) use ($sid) {
                $query->where('borrower_strategy_id', $sid)->orWhere('lender_strategy_id', $sid);
            })->whereIn('state', CapitalRecall::ACTIVE_STATES)->where('outstanding_recall_amount', '>', 0)->count();
        $requests = CapitalRequest::query()->where('profile_id', $pid)
            ->where(function ($query) use ($sid) {
                $query->where('borrower_strategy_id', $sid)->orWhere('lender_strategy_id', $sid);
            })->whereIn('status', [
                CapitalRequest::STATUS_DISPLAYED,
                CapitalRequest::STATUS_AWAITING_APPROVAL,
                CapitalRequest::STATUS_REVALIDATION_FAILED,
            ])->count();
        $settlements = PendingSaleProceeds::query()->where('profile_id', $pid)->where('strategy_id', $sid)
            ->whereIn('status', [PendingSaleProceeds::STATUS_PENDING, PendingSaleProceeds::STATUS_AVAILABLE])
            ->where(function ($query) {
                $query->where('amount', '>', 0)->orWhere('required_settlement_amount', '>', 0);
            })->count();

        $blockers = [];
        $this->add($blockers, 'owned_holdings', 'Open holdings owned by this strategy', $holdings, '/holdings');
        $this->add($blockers, 'actionable_recommendations', 'Actionable recommendations still require resolution', $actionable, '/recommendations');
        $this->add($blockers, 'pending_executions', 'Approved executions are still pending', $pendingExecutions, '/transactions/pending');
        $this->add($blockers, 'orders', 'Orders are pending or still in flight', $orders + $inFlightBrokerOrders, '/transactions/pending');
        $this->add($blockers, 'reserved_funds', 'Funds remain reserved for this strategy', $reserved, '/transactions/pending');
        $this->add($blockers, 'capital_requests', 'Capital requests remain unresolved', $requests, '/cash');
        $this->add($blockers, 'capital_loans', 'Capital loans involving this strategy remain outstanding', $loans + $bridgeLoans, '/cash');
        $this->add($blockers, 'capital_recalls', 'Capital recalls involving this strategy remain unresolved', $recalls, '/cash');
        $this->add($blockers, 'settlements', 'Sale proceeds remain unsettled for this strategy', $settlements, '/cash');

        return $blockers;
    }

    /** @param list<array{code:string,label:string,count:int,href:string}> $blockers */
    private function add(array &$blockers, string $code, string $label, int $count, string $href): void
    {
        if ($count > 0) {
            $blockers[] = compact('code', 'label', 'count', 'href');
        }
    }
}
