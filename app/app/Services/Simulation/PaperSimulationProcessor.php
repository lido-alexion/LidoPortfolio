<?php

namespace App\Services\Simulation;

use App\Engines\Recommendation\RecommendationEngine;
use App\Models\Holding;
use App\Models\PaperExecutionEvent;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Services\CashManagementService;
use App\Services\FeeCalculatorService;
use App\Services\TransactionWriteService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class PaperSimulationProcessor
{
    public function __construct(
        private SimulationPriceService $prices,
        private CashManagementService $cash,
        private TransactionWriteService $writes,
        private RecommendationEngine $recommendations,
        private FeeCalculatorService $fees,
    ) {}

    /** @return array<string, mixed> */
    public function process(PortfolioProfile $profile, string $through, int $maxSessions = 5): array
    {
        if (! $profile->isPaper()) {
            throw new \InvalidArgumentException('Paper processor requires a Paper portfolio.');
        }
        if ($profile->simulation_state === PortfolioProfile::SIMULATION_PAUSED) {
            return ['status' => 'paused', 'processed_sessions' => 0, 'checkpoint_date' => $profile->simulation_checkpoint_date?->toDateString()];
        }

        $cursor = $profile->simulation_checkpoint_date
            ? $profile->simulation_checkpoint_date->copy()->addDay()
            : Carbon::parse($profile->created_at)->startOfDay();
        $end = Carbon::parse($through)->startOfDay();
        $processed = 0;
        $fills = 0;
        while ($cursor->lte($end) && $processed < max(1, min($maxSessions, 31))) {
            if (! TradingCalendar::isEquitySessionDate($cursor)) {
                $cursor->addDay();
                continue;
            }
            $session = $cursor->toDateString();
            $result = $this->processSession($profile, $session);
            if ($result['waiting']) {
                $profile->forceFill(['simulation_state' => 'waiting'])->save();
                return ['status' => 'waiting', 'processed_sessions' => $processed, 'fills' => $fills, 'checkpoint_date' => $profile->simulation_checkpoint_date?->toDateString(), 'limitations' => $result['limitations']];
            }
            $fills += $result['fills'];
            $profile->forceFill(['simulation_state' => PortfolioProfile::SIMULATION_ACTIVE, 'simulation_checkpoint_date' => $session])->save();
            $processed++;
            $cursor->addDay();
        }

        return ['status' => $cursor->lte($end) ? 'behind' : 'current', 'processed_sessions' => $processed, 'fills' => $fills, 'checkpoint_date' => $profile->fresh()->simulation_checkpoint_date?->toDateString()];
    }

    /** @return array{waiting: bool, fills: int, limitations: array<int, string>} */
    private function processSession(PortfolioProfile $profile, string $session): array
    {
        $rows = TradingRecommendation::query()->where('profile_id', $profile->id)
            ->whereIn('status', [TradingRecommendation::STATUS_PENDING_REVIEW, TradingRecommendation::STATUS_DEFERRED, TradingRecommendation::STATUS_PENDING_EXECUTION, TradingRecommendation::STATUS_ACCEPTED])
            ->where(fn ($query) => $query->whereDate('first_eligible_execution_date', '<=', $session)
                ->orWhere(fn ($fallback) => $fallback->whereNull('first_eligible_execution_date')->whereDate('generated_at', '<', $session)))
            ->orderBy('id')->get();
        $fills = 0;
        $limitations = [];
        foreach ($rows as $recommendation) {
            if (! $recommendation->isActionable() || PaperExecutionEvent::query()->where('recommendation_id', $recommendation->id)->whereDate('effective_session_date', $session)->exists()) {
                continue;
            }
            $side = $recommendation->orderSide();
            $price = $this->prices->resolve((int) $recommendation->security_id, $session, (string) $profile->simulation_price_method, $side);
            if ($price['status'] !== 'ready') {
                $limitations = [...$limitations, ...$price['limitations']];
                return ['waiting' => true, 'fills' => $fills, 'limitations' => array_values(array_unique($limitations))];
            }
            $requested = $this->requestedQuantity($profile, $recommendation, $side, (float) $price['execution_price']);
            $executable = $side === 'buy'
                ? min($requested, $this->affordableQuantity($profile, $recommendation, (float) $price['execution_price'], $requested))
                : min($requested, (float) Holding::query()->where('profile_id', $profile->id)->where('stock_id', $recommendation->security_id)->sum('quantity'));
            $executable = max(0.0, floor($executable));
            $feeResult = $this->fees->calculate(
                $executable, (float) $price['execution_price'], $side,
                (string) ($recommendation->security?->exchange ?? 'NSE'),
            );
            $chargeModel = $this->fees->componentsFromSettings();

            DB::transaction(function () use ($profile, $recommendation, $session, $side, $price, $requested, $executable, $feeResult, $chargeModel, &$fills): void {
                $transaction = null;
                if ($executable > 0) {
                    $transaction = $this->writes->createFinancialUnit($profile, $recommendation->security, [
                        'type' => $side, 'quantity' => $executable, 'price' => $price['execution_price'], 'fees' => $feeResult['total'],
                        'transaction_date' => $session, 'notes' => 'Paper simulated execution for recommendation #'.$recommendation->id,
                        'source' => Transaction::SOURCE_RECOMMENDATION, 'recommendation_id' => $recommendation->id,
                        'owner_key' => Holding::ownerKeyFor($recommendation->owningStrategyId()),
                        'simulation_origin' => 'strategy_simulation',
                        'simulation_effective_session_date' => $session,
                        'simulation_processed_at' => now(),
                        'simulation_evidence' => [
                            'price_fingerprint' => $price['source']['fingerprint'],
                            'price_method' => $price['method'],
                            'artifact_version_id' => $recommendation->reusable_artifact_version_id,
                            'binding_revision_id' => $recommendation->artifact_binding_revision_id,
                        ],
                    ], user: null, applyCash: true);
                    $notional = round($executable * (float) $price['execution_price'], 4);
                    $priorExecuted = (float) $recommendation->external_executed_amount;
                    if ($executable >= $requested) {
                        $this->recommendations->markExecuted($recommendation, $transaction);
                        $recommendation->forceFill([
                            'external_executed_amount' => round($priorExecuted + $notional, 4),
                            'executed_amount' => round($priorExecuted + $notional, 4),
                            'remaining_target_amount' => 0,
                        ])->save();
                    } else {
                        $recommendation->forceFill([
                            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
                            'external_executed_amount' => round($priorExecuted + $notional, 4),
                            'executed_amount' => round($priorExecuted + $notional, 4),
                            'remaining_target_amount' => round(($requested - $executable) * $price['execution_price'], 4),
                        ])->save();
                    }
                    $fills++;
                }
                PaperExecutionEvent::query()->create([
                    'profile_id' => $profile->id, 'recommendation_id' => $recommendation->id,
                    'transaction_id' => $transaction?->id, 'effective_session_date' => $session,
                    'processed_at' => now(), 'status' => $executable > 0 ? ($executable >= $requested ? 'filled' : 'partial') : 'capital_constrained',
                    'side' => $side, 'requested_quantity' => $requested, 'executed_quantity' => $executable,
                    'execution_price' => $price['execution_price'],
                    'evidence' => [
                        'provenance' => 'paper_simulation', 'price' => $price,
                        'charge_model' => [
                            'version' => 'settings-sha256:'.hash('sha256', json_encode($chargeModel, JSON_THROW_ON_ERROR)),
                            'components' => $chargeModel, 'breakdown' => $feeResult['breakdown'], 'total' => $feeResult['total'],
                        ],
                        'processing_timestamp' => now()->toISOString(),
                    ],
                ]);
            });
        }

        return ['waiting' => false, 'fills' => $fills, 'limitations' => $limitations];
    }

    private function requestedQuantity(PortfolioProfile $profile, TradingRecommendation $recommendation, string $side, float $price): float
    {
        if ($side === 'sell' && $recommendation->portfolioAction() === TradingRecommendation::ACTION_EXIT_POSITION) {
            return (float) Holding::query()->where('profile_id', $profile->id)->where('stock_id', $recommendation->security_id)->sum('quantity');
        }
        if ($recommendation->remaining_target_amount !== null && (float) $recommendation->remaining_target_amount > 0.0) {
            return max(0.0, ceil((float) $recommendation->remaining_target_amount / $price));
        }
        $quantity = $recommendation->suggestedQuantity();
        if ($quantity !== null) {
            return max(0.0, floor($quantity));
        }

        return max(0.0, floor(((float) ($recommendation->suggestedInvestmentAmount() ?? 0.0)) / $price));
    }

    private function affordableQuantity(PortfolioProfile $profile, TradingRecommendation $recommendation, float $price, float $requested): float
    {
        $available = $this->cash->availableInvestableCash($profile);
        $quantity = min(floor($requested), floor($available / $price));
        $exchange = (string) ($recommendation->security?->exchange ?? 'NSE');
        while ($quantity > 0) {
            $fees = $this->fees->calculate($quantity, $price, 'buy', $exchange)['total'];
            if (($quantity * $price) + $fees <= $available + 0.0001) {
                break;
            }
            $quantity--;
        }

        return $quantity;
    }
}
