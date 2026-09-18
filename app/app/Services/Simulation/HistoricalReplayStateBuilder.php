<?php

namespace App\Services\Simulation;

use App\Models\ArtifactBinding;
use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\Holding;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReservationEvent;
use App\Models\RecallBridgeLoan;
use App\Models\RecallBridgeLoanReturn;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Services\HistoricalHoldingsService;
use Carbon\CarbonImmutable;

/** Builds an immutable, as-of Portfolio world for a historical Replay branch. */
final class HistoricalReplayStateBuilder
{
    public function __construct(
        private HistoricalHoldingsService $history,
    ) {}

    /** @return array{state: array<string,mixed>, binding_revisions: list<array<string,mixed>>, evidence: array<string,mixed>, limitations: list<string>, blockers: list<string>} */
    public function build(PortfolioProfile $profile, string $date): array
    {
        $boundary = CarbonImmutable::parse($date)->endOfDay();
        $historical = $this->history->asOf($profile, $date);
        $limitations = [];
        $blockers = [];

        if (! $historical['completeness']['cash_complete']) {
            $blockers[] = 'historical_cash_state_unavailable';
        }
        if (! $historical['completeness']['holdings_state_complete']) {
            $blockers[] = 'historical_holdings_state_unavailable';
        }
        if (! $historical['completeness']['valuation_complete']) {
            $limitations[] = 'historical_starting_valuation_incomplete';
        }

        [$holdings, $ownershipBlockers] = $this->holdingsAsOf($profile, $boundary);
        $blockers = array_merge($blockers, $ownershipBlockers);

        [$bindings, $bindingBlockers] = $this->bindingsAsOf($profile, $boundary);
        $blockers = array_merge($blockers, $bindingBlockers);

        [$reservations, $reservationBlockers] = $this->reservationsAsOf($profile, $boundary);
        $blockers = array_merge($blockers, $reservationBlockers);
        [$loans, $recalls, $bridges, $lendingBlockers] = $this->lendingAsOf($profile, $boundary);
        $blockers = array_merge($blockers, $lendingBlockers);
        $proceeds = $this->proceedsAsOf($profile, $boundary);

        $state = [
            'schema_version' => 1,
            'cash_balance' => $historical['totals']['cash_balance'],
            'holdings' => $holdings,
            'reservations' => $reservations,
            'loans' => $loans,
            'recalls' => $recalls,
            'recall_bridge_loans' => $bridges,
            'pending_sale_proceeds' => $proceeds,
            'transactions' => [],
            'strategies' => collect($bindings)->map(fn (array $binding): array => [
                'strategy_id' => $binding['strategy_id'],
                'artifact_version_id' => $binding['artifact_version_id'],
                'allocation_pct' => $binding['allocation_pct'],
                'owned_market_value' => 0.0,
                'reserved' => 0.0,
                'lent' => 0.0,
                'borrowed' => 0.0,
            ])->values()->all(),
            'source' => 'historical_portfolio_branch',
            'branch_date' => $boundary->toIso8601String(),
        ];

        $sum = collect($state['strategies'])->sum(fn (array $row): float => (float) ($row['allocation_pct'] ?? 0));
        if ($bindings !== [] && abs($sum - 100.0) > 0.01) {
            $blockers[] = 'historical_allocation_state_incomplete';
        }

        return [
            'state' => $state,
            'binding_revisions' => $bindings,
            'evidence' => [
                'as_of' => $boundary->toIso8601String(),
                'cash' => ['source' => 'portfolio_cash_ledger_entries', 'complete' => $historical['completeness']['cash_complete']],
                'holdings' => ['source' => 'portfolio_transactions', 'ownership_source' => 'transaction recommendation/owner_key'],
                'bindings' => ['source' => 'portfolio_artifact_binding_revisions', 'selection' => 'latest revision activated at or before branch boundary'],
                'reservations' => ['source' => 'portfolio_tos_recommendations', 'selection' => 'reserved at or before branch boundary'],
                'loans' => ['source' => 'portfolio_tos_loans and return ledger', 'selection' => 'committed/returned timestamps'],
            ],
            'limitations' => array_values(array_unique($limitations)),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @return array{list<array<string,mixed>>,list<string>} */
    private function holdingsAsOf(PortfolioProfile $profile, CarbonImmutable $boundary): array
    {
        $transactions = Transaction::query()->where('profile_id', $profile->id)
            ->whereDate('transaction_date', '<=', $boundary->toDateString())
            ->with('recommendation.strategyVersion')->orderBy('transaction_date')->orderBy('id')->get();
        $episodes = [];
        $blockers = [];

        foreach ($transactions as $transaction) {
            $stockId = (int) $transaction->stock_id;
            $strategyId = $transaction->owningStrategyId();
            $type = strtolower((string) $transaction->type);
            $matching = collect($episodes)->filter(fn (array $row): bool => $row['stock_id'] === $stockId && $row['quantity'] > 0.00001);
            if ($type !== 'buy' && $strategyId === null && $matching->count() > 1) {
                $blockers[] = 'historical_holding_ownership_ambiguous';
                continue;
            }
            if ($strategyId === null && $type !== 'buy' && $matching->count() === 1) {
                $strategyId = $matching->first()['strategy_id'];
            }
            $key = $stockId.':'.($strategyId ?? 'unmanaged');
            $row = $episodes[$key] ?? [
                'stock_id' => $stockId, 'strategy_id' => $strategyId,
                'owner_key' => Holding::ownerKeyFor($strategyId), 'quantity' => 0.0,
                'avg_buy_price' => 0.0, 'invested_amount' => 0.0,
            ];
            $quantity = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            if ($type === 'buy') {
                $row['invested_amount'] += $quantity * $price;
                $row['quantity'] += $quantity;
                $row['avg_buy_price'] = $row['quantity'] > 0 ? $row['invested_amount'] / $row['quantity'] : 0.0;
            } else {
                if ($quantity > $row['quantity'] + 0.00001) {
                    $blockers[] = 'historical_holding_quantity_unreconstructable';
                    continue;
                }
                $row['quantity'] -= $quantity;
                $row['invested_amount'] = $row['avg_buy_price'] * $row['quantity'];
            }
            $episodes[$key] = $row;
        }

        return [array_values(array_filter($episodes, fn (array $row): bool => $row['quantity'] > 0.00001)), array_values(array_unique($blockers))];
    }

    /** @return array{list<array<string,mixed>>,list<string>} */
    private function bindingsAsOf(PortfolioProfile $profile, CarbonImmutable $boundary): array
    {
        $bindings = ArtifactBinding::query()->where('profile_id', $profile->id)
            ->with('revisions.artifactVersion.artifact', 'revisions.artifactVersion.dependencies.targetVersion.artifact')->get();
        $rows = [];
        $blockers = [];
        foreach ($bindings as $binding) {
            $revision = $binding->revisions->filter(fn ($r): bool => $r->activated_at !== null && $r->activated_at->lte($boundary))
                ->sortByDesc(fn ($r) => $r->activated_at->getTimestamp())->first();
            if ($revision === null || $revision->binding_status !== ArtifactBinding::STATUS_ENABLED) continue;
            $strategy = TradingStrategy::query()->where('profile_id', $profile->id)->where('reusable_artifact_id', $binding->artifact_id)->first();
            if ($strategy === null) { $blockers[] = 'historical_strategy_binding_unavailable'; continue; }
            if ($strategy->updated_at?->gt($boundary)) {
                // The strategy row is mutable; without a versioned metadata
                // record, its later name/configuration cannot describe T.
                $blockers[] = 'historical_strategy_metadata_unavailable';
                continue;
            }
            $settings = is_array($revision->settings_json) ? $revision->settings_json : [];
            $allocation = $settings['allocation_pct'] ?? null;
            if ($allocation === null) {
                if ($strategy->created_at?->gt($boundary) || $strategy->updated_at?->gt($boundary)) { $blockers[] = 'historical_allocation_state_unavailable'; continue; }
                $allocation = $strategy->allocation_pct;
            }
            $version = $revision->artifactVersion;
            $content = is_array($version?->content_json) ? $version->content_json : [];
            $rows[] = [
                'binding_id' => $binding->id, 'binding_revision_id' => $revision->id, 'binding_settings' => $settings,
                'artifact_id' => $binding->artifact_id, 'artifact_version_id' => $revision->artifact_version_id,
                'artifact_name' => $version?->artifact?->name, 'artifact_version_semver' => $version?->semver,
                'definition_hash' => $version?->definition_hash, 'strategy_definition' => is_array($content['definition'] ?? null) ? $content['definition'] : [],
                'dependencies' => $version?->dependencies->map(fn ($dependency): array => [
                    'kind' => $dependency->kind, 'required' => (bool) $dependency->required,
                    'artifact_type' => $dependency->targetVersion?->artifact?->artifact_type,
                    'artifact_version_id' => $dependency->targetVersion?->id,
                    'definition_hash' => $dependency->targetVersion?->definition_hash,
                    'definition' => is_array($dependency->targetVersion?->content_json) ? ($dependency->targetVersion->content_json['definition'] ?? null) : null,
                    'indicator_id' => $dependency->indicator_id, 'indicator_version' => $dependency->indicator_version,
                ])->values()->all() ?? [],
                'usability_state' => $revision->usability_state, 'strategy_id' => $strategy->id, 'strategy_name' => $strategy->name,
                'allocation_pct' => $allocation !== null ? (float) $allocation : null,
            ];
        }
        return [$rows, array_values(array_unique($blockers))];
    }

    /** @return array{list<array<string,mixed>>,list<string>} */
    private function reservationsAsOf(PortfolioProfile $profile, CarbonImmutable $boundary): array
    {
        $rows = [];
        $blockers = [];
        $recommendations = TradingRecommendation::query()->where('profile_id', $profile->id)
            ->where(function ($query) use ($boundary): void {
                $query->where('reserved_at', '<=', $boundary)->orWhere('approved_at', '<=', $boundary);
            })->get();
        foreach ($recommendations as $recommendation) {
            $events = RecommendationReservationEvent::query()->where('recommendation_id', $recommendation->id)
                ->where('occurred_at', '<=', $boundary)->orderBy('occurred_at')->orderBy('id')->get();
            if ($events->isNotEmpty()) {
                $last = $events->last();
                if ($last->state === RecommendationReservationEvent::STATE_RESERVED) {
                    $rows[] = [
                        'recommendation_id' => $recommendation->id, 'strategy_id' => $recommendation->owningStrategyId(),
                        'amount' => (float) $last->amount, 'reserved_at' => $last->occurred_at?->toIso8601String(),
                    ];
                }
                continue;
            }

            // Legacy rows retain enough evidence only while still reserved.
            if ($recommendation->reservation_status === TradingRecommendation::RESERVATION_RESERVED
                && $recommendation->reserved_at !== null && $recommendation->reserved_at->lte($boundary)) {
                $rows[] = [
                    'recommendation_id' => $recommendation->id, 'strategy_id' => $recommendation->owningStrategyId(),
                    'amount' => (float) $recommendation->reserved_amount, 'reserved_at' => $recommendation->reserved_at->toIso8601String(),
                ];
            } elseif ($recommendation->approved_at?->lte($boundary) && $recommendation->requiresCashReservation()) {
                // Release/conversion cleared the legacy timestamp; without an
                // immutable event we cannot know whether it happened before T.
                $blockers[] = 'historical_reservation_state_unavailable';
            }
        }
        return [$rows, array_values(array_unique($blockers))];
    }

    /** @return array{list<array<string,mixed>>,list<array<string,mixed>>,list<array<string,mixed>>,list<string>} */
    private function lendingAsOf(PortfolioProfile $profile, CarbonImmutable $boundary): array
    {
        $blockers = [];
        $loans = CapitalLoan::query()->where('profile_id', $profile->id)->where('committed_at', '<=', $boundary)->with('returns')->get()->map(function (CapitalLoan $loan) use ($boundary): array {
            $returned = $loan->returns->filter(fn ($return): bool => $return->returned_at !== null && $return->returned_at->lte($boundary))->sum('amount');
            $status = $returned <= 0.0001
                ? CapitalLoan::STATUS_OUTSTANDING
                : ((float) $returned + 0.0001 >= (float) $loan->principal ? CapitalLoan::STATUS_RETURNED : CapitalLoan::STATUS_PARTIALLY_RETURNED);
            return ['loan_id' => $loan->id, 'lender_strategy_id' => $loan->lender_strategy_id, 'borrower_strategy_id' => $loan->borrower_strategy_id,
                'outstanding' => round(max(0.0, (float) $loan->principal - (float) $returned), 4), 'status' => $status];
        })->filter(fn (array $loan): bool => $loan['outstanding'] > 0.0001)->values()->all();
        $recalls = [];
        foreach (CapitalRecall::query()->where('profile_id', $profile->id)->where('requested_at', '<=', $boundary)->get() as $recall) {
            if ($recall->completed_at !== null && $recall->completed_at->lte($boundary)) continue;
            if ($recall->state !== CapitalRecall::STATE_REQUESTED) {
                $blockers[] = 'historical_recall_state_unavailable';
                continue;
            }
            $recalls[] = ['recall_id' => $recall->id, 'loan_id' => $recall->loan_id, 'lender_strategy_id' => $recall->lender_strategy_id,
                'borrower_strategy_id' => $recall->borrower_strategy_id, 'outstanding' => (float) $recall->recall_amount, 'state' => CapitalRecall::STATE_REQUESTED];
        }
        $bridges = [];
        foreach (RecallBridgeLoan::query()->where('profile_id', $profile->id)->where('committed_at', '<=', $boundary)->get() as $loan) {
            $returns = RecallBridgeLoanReturn::query()->where('bridge_loan_id', $loan->id)->where('returned_at', '<=', $boundary)->sum('amount');
            $hasReturns = RecallBridgeLoanReturn::query()->where('bridge_loan_id', $loan->id)->exists();
            if (! $hasReturns && $loan->status !== RecallBridgeLoan::STATUS_OUTSTANDING) {
                $blockers[] = 'historical_bridge_loan_state_unavailable';
                continue;
            }
            $outstanding = round(max(0.0, (float) $loan->principal - (float) $returns), 4);
            if ($outstanding <= 0.0001) continue;
            $bridges[] = ['bridge_loan_id' => $loan->id, 'lender_strategy_id' => $loan->lender_strategy_id, 'borrower_strategy_id' => $loan->borrower_strategy_id,
                'outstanding' => $outstanding, 'status' => $returns <= 0.0001 ? RecallBridgeLoan::STATUS_OUTSTANDING : RecallBridgeLoan::STATUS_PARTIALLY_RETURNED];
        }
        return [$loans, $recalls, $bridges, array_values(array_unique($blockers))];
    }

    /** @return list<array<string,mixed>> */
    private function proceedsAsOf(PortfolioProfile $profile, CarbonImmutable $boundary): array
    {
        return PendingSaleProceeds::query()->where('profile_id', $profile->id)->where('sold_at', '<=', $boundary)
            ->where(function ($query) use ($boundary): void { $query->whereNull('cash_released_at')->orWhere('cash_released_at', '>', $boundary); })
            ->get()->map(function (PendingSaleProceeds $row) use ($boundary): array {
                $status = $row->available_at !== null && $row->available_at->lte($boundary)
                    ? PendingSaleProceeds::STATUS_AVAILABLE
                    : PendingSaleProceeds::STATUS_PENDING;
                return ['id' => $row->id, 'strategy_id' => $row->strategy_id, 'amount' => (float) $row->amount,
                    'available_at' => $row->available_at?->toIso8601String(), 'status' => $status];
            })->all();
    }
}
