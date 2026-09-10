<?php

namespace App\Services\Simulation;

use App\Services\FeeCalculatorService;

final class ReplayTradeTransition
{
    public function __construct(
        private SimulationPriceService $prices,
        private FeeCalculatorService $fees,
    ) {}

    /** @return array{state:array<string,mixed>,waiting:bool,limitations:list<string>,fills:int} */
    public function apply(array $state, string $session, array $assumptions): array
    {
        $recommendations = $state['pending_recommendations'] ?? [];
        $limitations = [];
        $fills = 0;
        foreach ($recommendations as $index => $recommendation) {
            if (($recommendation['status'] ?? 'pending') !== 'pending'
                || (string) ($recommendation['first_eligible_session'] ?? '') > $session) {
                continue;
            }
            $side = strtolower((string) ($recommendation['side'] ?? 'buy'));
            $price = $this->prices->resolve(
                (int) $recommendation['stock_id'], $session,
                (string) ($assumptions['price_method'] ?? 'next_open'), $side,
                (float) ($assumptions['adverse_slippage_percent'] ?? 0),
            );
            if ($price['status'] !== 'ready') {
                $limitations = array_values(array_unique([...$limitations, ...$price['limitations']]));

                return compact('state', 'limitations', 'fills') + ['waiting' => true];
            }

            $requested = $this->requestedQuantity($recommendation, (float) $price['execution_price']);
            $available = $side === 'buy'
                ? $this->affordableQuantity($state, $requested, (float) $price['execution_price'], $recommendation, $assumptions)
                : $this->heldQuantity($state, $recommendation);
            $quantity = max(0.0, floor(min($requested, $available)));
            $charge = $this->fees->calculate(
                $quantity, (float) $price['execution_price'], $side,
                (string) ($recommendation['exchange'] ?? 'NSE'),
                $assumptions['charge_model']['components'] ?? null,
            );
            if ($quantity > 0) {
                $state = $this->postTransaction($state, $recommendation, $session, $side, $quantity, $price, $charge, $assumptions);
                $fills++;
            }
            $state['pending_recommendations'][$index]['executed_quantity'] = $quantity;
            $state['pending_recommendations'][$index]['status'] = $quantity <= 0
                ? 'capital_constrained' : ($quantity >= $requested ? 'filled' : 'partial');
        }

        return compact('state', 'limitations', 'fills') + ['waiting' => false];
    }

    private function requestedQuantity(array $recommendation, float $price): float
    {
        if (isset($recommendation['quantity'])) {
            return max(0.0, floor((float) $recommendation['quantity']));
        }

        return $price > 0 ? max(0.0, ceil((float) ($recommendation['target_amount'] ?? 0) / $price)) : 0.0;
    }

    private function affordableQuantity(array $state, float $requested, float $price, array $recommendation, array $assumptions): float
    {
        $cash = max(0.0, (float) ($state['cash_balance'] ?? 0));
        $components = $assumptions['charge_model']['components'] ?? null;
        for ($quantity = (int) floor($requested); $quantity > 0; $quantity--) {
            $fees = $this->fees->calculate($quantity, $price, 'buy', (string) ($recommendation['exchange'] ?? 'NSE'), $components)['total'];
            if (($quantity * $price) + $fees <= $cash + 0.0001) {
                return (float) $quantity;
            }
        }

        return 0.0;
    }

    private function heldQuantity(array $state, array $recommendation): float
    {
        foreach ($state['holdings'] ?? [] as $holding) {
            if ((int) ($holding['stock_id'] ?? 0) === (int) $recommendation['stock_id']
                && (int) ($holding['strategy_id'] ?? 0) === (int) ($recommendation['strategy_id'] ?? 0)) {
                return (float) ($holding['quantity'] ?? 0);
            }
        }

        return 0.0;
    }

    private function postTransaction(array $state, array $recommendation, string $session, string $side, float $quantity, array $price, array $charge, array $assumptions): array
    {
        $notional = round($quantity * (float) $price['execution_price'], 4);
        $cashDelta = $side === 'buy' ? -($notional + $charge['total']) : ($notional - $charge['total']);
        $state['cash_balance'] = round((float) ($state['cash_balance'] ?? 0) + $cashDelta, 4);
        $holdingIndex = null;
        foreach ($state['holdings'] ?? [] as $index => $holding) {
            if ((int) ($holding['stock_id'] ?? 0) === (int) $recommendation['stock_id']
                && (int) ($holding['strategy_id'] ?? 0) === (int) ($recommendation['strategy_id'] ?? 0)) {
                $holdingIndex = $index;
            }
        }
        $holding = $holdingIndex === null ? [
            'stock_id' => (int) $recommendation['stock_id'],
            'strategy_id' => $recommendation['strategy_id'] ?? null,
            'quantity' => 0.0, 'avg_buy_price' => 0.0, 'invested_amount' => 0.0,
        ] : $state['holdings'][$holdingIndex];
        if ($side === 'buy') {
            $oldQuantity = (float) $holding['quantity'];
            $newQuantity = $oldQuantity + $quantity;
            $holding['avg_buy_price'] = round((($oldQuantity * (float) $holding['avg_buy_price']) + $notional) / $newQuantity, 4);
            $holding['quantity'] = $newQuantity;
            $holding['invested_amount'] = round($newQuantity * $holding['avg_buy_price'], 4);
        } else {
            $holding['quantity'] = max(0.0, (float) $holding['quantity'] - $quantity);
            $holding['invested_amount'] = round($holding['quantity'] * (float) $holding['avg_buy_price'], 4);
        }
        if ($holdingIndex === null) {
            $state['holdings'][] = $holding;
        } else {
            $state['holdings'][$holdingIndex] = $holding;
        }

        $state['transactions'][] = [
            'recommendation_key' => $recommendation['key'] ?? null,
            'effective_session_date' => $session, 'side' => $side, 'quantity' => $quantity,
            'price' => $price['execution_price'], 'fees' => $charge['total'],
            'stock_id' => (int) $recommendation['stock_id'], 'strategy_id' => $recommendation['strategy_id'] ?? null,
            'provenance' => 'portfolio_replay',
            'evidence' => ['price' => $price, 'charge_model_version' => $assumptions['charge_model']['version'] ?? null, 'charge_breakdown' => $charge['breakdown']],
        ];

        return $state;
    }
}
