<?php

namespace App\Engines\Recommendation\Allocation;

/**
 * Score-weighted capital allocator (SD-026).
 * Distributes available cash across buy drafts proportional to score,
 * then whole-share rounds and greedily assigns leftover to highest scores.
 */
class ScorePriorityCapitalAllocator implements CapitalAllocationStrategy
{
    public function allocate(float $availableCash, array $buyDrafts): array
    {
        $remaining = max(0.0, $availableCash);
        $out = [];

        if ($buyDrafts === [] || $remaining < 0.01) {
            foreach ($buyDrafts as $draft) {
                $out[$draft['key']] = ['allocated_amount' => 0.0, 'quantity' => 0];
            }

            return $out;
        }

        $sorted = $buyDrafts;
        usort($sorted, function (array $a, array $b): int {
            $scoreCmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
        });

        $weights = [];
        $totalWeight = 0.0;
        foreach ($sorted as $draft) {
            $w = max(0.01, (float) ($draft['score'] ?? 1));
            $weights[$draft['key']] = $w;
            $totalWeight += $w;
        }

        $pool = $remaining;
        // First pass: proportional budgets from the full pool → whole shares.
        foreach ($sorted as $draft) {
            $key = $draft['key'];
            $price = (float) ($draft['reference_price'] ?? 0);
            $desired = max(0.0, (float) ($draft['desired_amount'] ?? 0));
            $maxPos = $draft['max_position_amount'] ?? null;
            if ($maxPos !== null) {
                $desired = min($desired, max(0.0, (float) $maxPos));
            }

            $share = $totalWeight > 0 ? ($weights[$key] / $totalWeight) : 0;
            $budget = min($desired, $pool * $share);

            if ($price <= 0 || $budget < $price) {
                $out[$key] = ['allocated_amount' => 0.0, 'quantity' => 0];

                continue;
            }

            $qty = (int) floor($budget / $price);
            $amount = round($qty * $price, 4);
            $out[$key] = [
                'allocated_amount' => $amount,
                'quantity' => $qty,
            ];
            $remaining = round($remaining - $amount, 4);
        }

        // Second pass: leftover cash → highest score first (still respecting desired/max).
        foreach ($sorted as $draft) {
            if ($remaining < 0.01) {
                break;
            }
            $key = $draft['key'];
            $price = (float) ($draft['reference_price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $desired = max(0.0, (float) ($draft['desired_amount'] ?? 0));
            $maxPos = $draft['max_position_amount'] ?? null;
            if ($maxPos !== null) {
                $desired = min($desired, max(0.0, (float) $maxPos));
            }
            $already = (float) ($out[$key]['allocated_amount'] ?? 0);
            $room = max(0.0, $desired - $already);
            $addBudget = min($room, $remaining);
            $extraQty = (int) floor($addBudget / $price);
            if ($extraQty < 1) {
                continue;
            }
            $extraAmt = round($extraQty * $price, 4);
            $out[$key]['quantity'] = (int) ($out[$key]['quantity'] ?? 0) + $extraQty;
            $out[$key]['allocated_amount'] = round($already + $extraAmt, 4);
            $remaining = round($remaining - $extraAmt, 4);
        }

        return $out;
    }
}
