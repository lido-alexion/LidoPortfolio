<?php

namespace App\Services\Reconciliation;

/**
 * Conservative, non-mutating comparison of StoX holdings and Kite CNC evidence.
 * This class deliberately does not produce an adjusted broker quantity.
 */
final class KiteSettlementHoldingsEvidence
{
    /**
     * @param  list<array<string,mixed>>  $stoxHoldings
     * @param  array<string,mixed>  $brokerSnapshot
     * @param  list<array<string,mixed>>  $persistedTrades
     * @return array{status:string,account_id:int,as_of:string,holdings:list<array<string,mixed>>,unresolved:list<array<string,mixed>>,cash_status:string,policy:string}
     */
    public function compare(int $accountId, string $asOf, array $stoxHoldings, array $brokerSnapshot, array $persistedTrades, bool $tradeEvidenceComplete): array
    {
        if (! $this->validDate($asOf)) {
            throw new \InvalidArgumentException('The holdings evidence as-of date must be a valid YYYY-MM-DD date.');
        }
        $stoxRowsValid = $this->validHoldingRows($stoxHoldings, false);
        $stox = $this->aggregateHoldings($stoxHoldings, false, true);
        $rawBrokerRows = $brokerSnapshot['holdings'] ?? null;
        $rawBrokerRowsValid = array_key_exists('holdings', $brokerSnapshot) && is_array($rawBrokerRows);
        if (! $rawBrokerRowsValid) {
            $rawBrokerRows = [];
        }
        $brokerRows = array_values(array_filter($rawBrokerRows, static fn ($row) => is_array($row)));
        $brokerRowsValid = $rawBrokerRowsValid && count($rawBrokerRows) === count($brokerRows);
        foreach ($brokerRows as $row) {
            $brokerRowsValid = $brokerRowsValid && $this->validHoldingRows([$row], true);
        }
        $broker = $this->aggregateHoldings($brokerRows, true);
        $brokerAccount = $brokerSnapshot['account_id'] ?? $brokerSnapshot['profile_id'] ?? null;
        $accountProvenanceValid = is_numeric($brokerAccount) && (int) $brokerAccount === $accountId;
        $snapshotFresh = $this->snapshotIsFresh($brokerSnapshot, $asOf);
        $brokerHoldingsComplete = ($brokerSnapshot['holdings_complete'] ?? false) === true && $brokerRowsValid && $accountProvenanceValid && $snapshotFresh;
        $positions = $brokerSnapshot['positions'] ?? [];
        $positionsValid = is_array($positions);
        if (! $positionsValid) {
            $positions = [];
        }
        $positionsComplete = $positionsValid && (($positions['complete'] ?? false) === true);
        foreach (['net', 'day'] as $bucket) {
            $positionRows = $positions[$bucket] ?? null;
            if (! array_key_exists($bucket, $positions) || ! is_array($positionRows)) {
                $positionsComplete = false;
                $positionRows = [];
            }
            foreach ($positionRows as $position) {
                if (! is_array($position) || $this->identity($position) === null
                    || ! $this->validQuantity($position['quantity'] ?? $position['net_quantity'] ?? null, true)) {
                    $positionsComplete = false;
                }
            }
        }
        $trades = [];
        $tradeConflicts = [];
        $tradeConflictIdentities = [];
        $tradeRowsValid = true;
        foreach ($persistedTrades as $trade) {
            if (! is_array($trade) || ! isset($trade['profile_id']) || ! is_numeric($trade['profile_id'])) {
                $tradeRowsValid = false;

                continue;
            }
            if ((int) $trade['profile_id'] !== $accountId) {
                continue;
            }
            if (! isset($trade['product'])) {
                $tradeRowsValid = false;

                continue;
            }
            if (strtoupper((string) $trade['product']) !== 'CNC') {
                continue;
            }
            $tradeId = trim((string) ($trade['broker_trade_id'] ?? ''));
            if ($tradeId === '' || ! isset($trade['executed_at'], $trade['settles_by'], $trade['quantity'], $trade['side'])
                || ! $this->validDate(substr((string) ($trade['executed_at'] ?? ''), 0, 10))
                || ! $this->validDate((string) ($trade['settles_by'] ?? ''))
                || ! $this->validQuantity($trade['quantity'] ?? null, false)
                || ! in_array(strtolower((string) ($trade['side'] ?? '')), ['buy', 'sell'], true)
                || $this->identity($trade) === null) {
                $tradeRowsValid = false;

                continue;
            }
            if (substr((string) $trade['executed_at'], 0, 10) > $asOf) {
                continue;
            }
            $fingerprint = implode('|', [strtoupper(trim((string) ($trade['isin'] ?? ''))), (string) ($trade['symbol'] ?? ''), (string) ($trade['exchange'] ?? ''), strtolower((string) $trade['side']), (string) $trade['quantity'], (string) $trade['executed_at'], (string) $trade['settles_by']]);
            if (isset($trades[$tradeId]) && $trades[$tradeId]['fingerprint'] !== $fingerprint) {
                $tradeConflicts[$tradeId] = true;
                $tradeConflictIdentities[$trades[$tradeId]['identity']] = true;
                $tradeConflictIdentities[$this->identity($trade)] = true;

                continue;
            }
            $trades[$tradeId] = [...$trade, 'fingerprint' => $fingerprint, 'identity' => $this->identity($trade)];
        }

        $activeTradeIdentities = [];
        foreach ($trades as $trade) {
            if ((string) $trade['settles_by'] >= $asOf) {
                $activeTradeIdentities[$trade['identity']] = true;
            }
        }
        $identities = array_unique([...array_keys($stox), ...array_keys($broker), ...array_keys($activeTradeIdentities), ...array_keys($tradeConflictIdentities)]);
        sort($identities);
        $rows = [];
        $unresolved = [];
        if (! $stoxRowsValid) {
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'stox_holdings_invalid'];
        }
        if (! $accountProvenanceValid) {
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'broker_snapshot_account_unknown_or_mismatched'];
        }
        if (! $snapshotFresh) {
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'broker_snapshot_stale_or_timestamp_unknown'];
        }
        if (! $tradeEvidenceComplete || ! $tradeRowsValid) {
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'trade_evidence_partial_or_unknown'];
        }
        if ($tradeConflicts !== []) {
            // Keep the global result conservative even when conflicting executions are expired,
            // trade-only, or otherwise outside the current holdings identity set. Do not expose IDs.
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'conflicting_duplicate_trade_id'];
        }
        if (! $positionsComplete) {
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'broker_positions_partial_or_unknown'];
        }
        foreach ($identities as $identity) {
            $local = $stox[$identity] ?? null;
            $remote = $broker[$identity] ?? null;
            $localQty = (float) ($local['quantity'] ?? 0);
            $brokerQty = (float) ($remote['comparison_quantity'] ?? $remote['quantity'] ?? 0);
            $gap = round($localQty - $brokerQty, 4);
            $active = array_filter($trades, static fn ($trade) => $trade['identity'] === $identity && (string) $trade['settles_by'] >= $asOf);
            $hasTradeConflict = count(array_intersect(array_keys($tradeConflicts), array_map(static fn ($trade) => (string) $trade['broker_trade_id'], $active))) > 0;
            $net = 0.0;
            $activeSides = [];
            foreach ($active as $trade) {
                $net += (strtolower((string) $trade['side']) === 'buy' ? 1 : -1) * (float) $trade['quantity'];
            }
            foreach ($active as $trade) {
                $activeSides[strtolower((string) $trade['side'])] = true;
            }
            $net = round($net, 4);
            $positionEvidence = $this->positionRowsFor($positions, $identity);
            $reason = null;
            $state = 'matched';
            if (($remote['conflict'] ?? false) === true) {
                $state = 'unresolved';
                $reason = 'conflicting_duplicate_broker_holdings';
            } elseif ($hasTradeConflict || isset($tradeConflictIdentities[$identity])) {
                $state = 'unresolved';
                $reason = 'conflicting_duplicate_trade_id';
            } elseif ($active !== [] && $gap === 0.0) {
                $state = 'unresolved';
                $reason = 'active_trade_reflection_unproven';
            } elseif ($gap !== 0.0 && ! $brokerHoldingsComplete) {
                $state = 'unresolved';
                $reason = 'broker_holdings_partial_or_unknown';
            } elseif ($gap !== 0.0) {
                if (! $tradeEvidenceComplete || ! $tradeRowsValid) {
                    $state = 'unresolved';
                    $reason = 'trade_evidence_partial_or_unknown';
                } elseif ($active !== [] && $positionEvidence !== []) {
                    $state = 'unresolved';
                    $reason = 'broker_positions_overlap_unclassified_trade_reflection';
                } elseif ($active !== [] && ! $positionsComplete) {
                    $state = 'unresolved';
                    $reason = 'broker_positions_partial_or_unknown';
                } elseif ($active !== [] && count($activeSides) > 1) {
                    // Netting opposite CNC fills can hide partial reflection on either side.
                    // Without row-level broker reflection evidence, the net is not proof.
                    $state = 'unresolved';
                    $reason = 'mixed_cnc_trade_reflection_unclassified';
                } elseif ($active !== [] && abs($net - $gap) < 0.0001 && ! array_intersect(array_keys($tradeConflicts), array_map(static fn ($trade) => (string) $trade['broker_trade_id'], $active))) {
                    $state = 'pending_settlement_exact_evidence';
                } elseif ($active !== []) {
                    $state = 'unresolved';
                    $reason = 'trade_evidence_does_not_fully_explain_quantity_gap';
                } else {
                    $state = 'unresolved';
                    $reason = 'quantity_gap_without_active_settlement_evidence';
                }
            }
            // Position quantities overlap holdings/trade evidence. They are surfaced for review, never added.
            if ($positionEvidence !== [] && $state === 'pending_settlement_exact_evidence') {
                $state = 'unresolved';
                $reason = 'broker_positions_overlap_unclassified_trade_reflection';
            }
            $row = ['identity' => $identity, 'symbol' => $local['symbol'] ?? $remote['symbol'] ?? null,
                'stox_quantity' => $localQty, 'kite_reported_quantity' => (float) ($remote['quantity'] ?? 0),
                'kite_comparison_quantity' => $brokerQty,
                'kite_t1_quantity' => (float) ($remote['t1_quantity'] ?? 0),
                'kite_used_quantity' => (float) ($remote['used_quantity'] ?? 0),
                'quantity_gap' => $gap, 'active_trade_net' => $net,
                'active_trade_ids' => array_values(array_map(static fn ($trade) => (string) $trade['broker_trade_id'], $active)),
                'state' => $state, 'reason' => $reason,
                'position_evidence_present' => $positionEvidence !== [],
                'settles_by' => $active === [] ? null : max(array_map(static fn ($trade) => (string) $trade['settles_by'], $active))];
            $rows[] = $row;
            if ($state === 'unresolved') {
                $unresolved[] = $row;
            }
        }

        if (! $brokerHoldingsComplete && $rows === []) {
            $unresolved[] = ['identity' => null, 'state' => 'unresolved', 'reason' => 'broker_holdings_partial_or_unknown'];
        }
        $pendingSettlementExists = count(array_filter($rows, static fn ($row) => $row['state'] === 'pending_settlement_exact_evidence')) > 0;
        $cashComparable = ($brokerSnapshot['cash_comparable'] ?? false) === true;

        return ['status' => $unresolved === [] && ! $pendingSettlementExists && $brokerHoldingsComplete && $stoxRowsValid && $positionsComplete && $tradeEvidenceComplete && $tradeRowsValid ? 'evidence_consistent' : 'review_required', 'account_id' => $accountId,
            'as_of' => $asOf, 'holdings' => $rows, 'unresolved' => $unresolved,
            'cash_status' => $cashComparable ? 'requires_separate_cash_reconciliation' : 'unknown_non_comparable_broker_funds',
            'policy' => 'shadow_only_no_quantity_adjustment_no_execution_gate'];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function aggregateHoldings(array $rows, bool $broker = false, bool $sumRows = false): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $identity = $this->identity($row);
            if ($identity === null) {
                continue;
            }
            $quantity = round((float) ($row['quantity'] ?? 0), 4);
            $t1 = round((float) ($row['t1_quantity'] ?? 0), 4);
            $used = round((float) ($row['used_quantity'] ?? 0), 4);
            $fingerprint = implode('|', [$quantity, $t1, $used]);
            if (! isset($grouped[$identity])) {
                $grouped[$identity] = [...$row, 'quantity' => $quantity, 'comparison_quantity' => $broker ? round($quantity + $t1 - $used, 4) : $quantity,
                    '_fingerprints' => [$fingerprint]];
            } elseif ($sumRows) {
                $grouped[$identity]['quantity'] = round((float) $grouped[$identity]['quantity'] + $quantity, 4);
                $grouped[$identity]['comparison_quantity'] = $grouped[$identity]['quantity'];
            } else {
                $grouped[$identity]['_fingerprints'][] = $fingerprint;
            }
        }
        foreach ($grouped as &$row) {
            $unique = array_values(array_unique($row['_fingerprints']));
            $row['conflict'] = $broker && count($unique) > 1;
            // Exact duplicate appearance is one observation, not additive inventory.
            unset($row['_fingerprints']);
        }
        unset($row);

        return $grouped;
    }

    private function validHoldingRows(array $rows, bool $broker): bool
    {
        foreach ($rows as $row) {
            if (! is_array($row) || $this->identity($row) === null
                || ! $this->validQuantity($row['quantity'] ?? null, true)) {
                return false;
            }
            if ($broker && ((! $this->validQuantity($row['t1_quantity'] ?? 0, true))
                || (! $this->validQuantity($row['used_quantity'] ?? 0, true)))) {
                return false;
            }
        }

        return true;
    }

    private function validQuantity(mixed $value, bool $allowZero): bool
    {
        if (! is_numeric($value)) {
            return false;
        }
        $number = (float) $value;

        return is_finite($number) && ($allowZero ? $number >= 0 : $number > 0);
    }

    private function snapshotIsFresh(array $snapshot, string $asOf): bool
    {
        $captured = $snapshot['captured_at'] ?? null;
        if (! is_string($captured) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', trim($captured)) !== 1) {
            return false;
        }
        try {
            $date = new \DateTimeImmutable($captured);
        } catch (\Throwable) {
            return false;
        }

        return $date->format('Y-m-d') === $asOf && $date <= new \DateTimeImmutable($asOf.' 23:59:59.999999');
    }

    private function identity(array $row): ?string
    {
        $isin = strtoupper(trim((string) ($row['isin'] ?? '')));
        if ($isin !== '') {
            return 'isin:'.$isin;
        }
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        $exchange = strtoupper(trim((string) ($row['exchange'] ?? '')));

        return $symbol === '' || $exchange === '' ? null : 'symbol:'.$exchange.':'.$symbol;
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** @return list<array<string,mixed>> */
    private function positionRowsFor(array $positions, string $identity): array
    {
        $rows = [];
        foreach (['net', 'day'] as $bucket) {
            $bucketRows = $positions[$bucket] ?? [];
            if (! is_array($bucketRows)) {
                continue;
            }
            foreach ($bucketRows as $row) {
                if (is_array($row) && $this->identity($row) === $identity && (float) ($row['quantity'] ?? $row['net_quantity'] ?? 0) != 0.0) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
}
