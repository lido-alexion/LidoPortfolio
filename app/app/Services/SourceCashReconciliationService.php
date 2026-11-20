<?php

namespace App\Services;

use InvalidArgumentException;

/** Read-only reconstruction from an explicitly supplied, profile-scoped evidence file. */
class SourceCashReconciliationService
{
    public function reconcile(array $source): array
    {
        $profileId = $source['profile_id'] ?? null;
        if (! is_int($profileId) && ! (is_string($profileId) && ctype_digit($profileId))) {
            throw new InvalidArgumentException('profile_id is required.');
        }
        $profileId = (int) $profileId;
        $transactions = $this->uniqueRows($source['transactions'] ?? [], 'transactions');
        $bankFlows = $this->uniqueRows($source['bank_flows'] ?? [], 'bank_flows');
        $brokerFlows = $this->uniqueRows($source['broker_flows'] ?? [], 'broker_flows');

        $tradeFlows = [];
        $tradeIds = [];
        foreach ($transactions as $row) {
            $this->assertProfile($row, $profileId, 'transaction');
            $id = (string) $row['id'];
            $tradeIds[$id] = true;
            $type = strtolower((string) ($row['type'] ?? ''));
            $quantity = $this->positive($row['quantity'] ?? null, 'transaction quantity');
            $price = $this->positive($row['price'] ?? null, 'transaction price');
            $fees = $this->nonNegative($row['fees'] ?? null, 'transaction fees');
            $notional = $quantity * $price;
            // Stored fees include any DP fee allocated to the transaction.
            $cash = $type === 'buy' ? -($notional + $fees) : ($type === 'sell' ? $notional - $fees : null);
            if ($cash === null) throw new InvalidArgumentException("Transaction {$id} must be buy or sell.");
            $tradeFlows[] = ['id' => $id, 'date' => $this->date($row['date'] ?? null), 'type' => $type, 'notional' => round($notional, 4), 'fees_including_dp' => round($fees, 4), 'cash_delta' => round($cash, 4)];
        }

        $bank = [];
        foreach ($bankFlows as $row) {
            $this->assertProfile($row, $profileId, 'bank flow');
            $amount = $this->positive($row['amount'] ?? null, 'bank flow amount');
            $type = strtolower((string) ($row['type'] ?? ''));
            if (! in_array($type, ['deposit', 'withdrawal'], true)) throw new InvalidArgumentException('Bank flow type must be deposit or withdrawal.');
            $bank[] = ['id' => (string) $row['id'], 'date' => $this->date($row['date'] ?? null), 'type' => $type, 'cash_delta' => round($type === 'deposit' ? $amount : -$amount, 4)];
        }

        $linkedTrades = [];
        $dp = [];
        foreach ($brokerFlows as $row) {
            $this->assertProfile($row, $profileId, 'broker flow');
            $kind = (string) ($row['kind'] ?? '');
            if ($kind === 'dp_charge') {
                $dp[] = ['id' => (string) $row['id'], 'date' => $this->date($row['date'] ?? null), 'amount' => round($this->positive($row['amount'] ?? null, 'DP charge'), 4), 'treatment' => 'informational; check transaction fees already include DP before adding to cash'];
                continue;
            }
            if ($kind !== 'trade_link') throw new InvalidArgumentException('broker_flows kind must be trade_link or dp_charge.');
            $transactionId = (string) ($row['transaction_id'] ?? '');
            if (! isset($tradeIds[$transactionId])) throw new InvalidArgumentException("Broker trade link {$row['id']} references an unknown transaction.");
            if (isset($linkedTrades[$transactionId])) throw new InvalidArgumentException("Transaction {$transactionId} has inconsistent duplicate broker trade links.");
            $linkedTrades[$transactionId] = true;
            $reported = (float) ($row['cash_delta'] ?? NAN);
            $expected = array_values(array_filter($tradeFlows, fn ($trade) => $trade['id'] === $transactionId))[0]['cash_delta'];
            if (! is_finite($reported) || abs(round($reported, 4) - $expected) > 0.0001) throw new InvalidArgumentException("Broker trade link {$row['id']} cash does not match transaction {$transactionId} quantity, price, and stored fees.");
        }

        $events = [...$tradeFlows, ...$bank];
        $snapshot = $source['closing_balance_snapshot'] ?? null;
        $opening = null;
        $closing = round(array_sum(array_column($events, 'cash_delta')), 4);
        $openingEvidence = null;
        if ($snapshot !== null) {
            if (! is_array($snapshot) || empty($snapshot['id']) || empty($snapshot['date']) || ! array_key_exists('balance', $snapshot)) throw new InvalidArgumentException('closing_balance_snapshot requires id, date, and balance.');
            $asOf = $this->date($snapshot['date']);
            foreach ($events as $event) if ($event['date'] > $asOf) throw new InvalidArgumentException('All supplied cash events must be on or before the closing snapshot date.');
            $closing = round((float) $snapshot['balance'], 4);
            $opening = round($closing - array_sum(array_column($events, 'cash_delta')), 4);
            $openingEvidence = ['kind' => 'derived_opening_balance', 'amount' => $opening, 'as_of' => $asOf, 'based_on_snapshot_id' => (string) $snapshot['id'], 'provenance' => 'derived residual; not an observed bank deposit or broker cash movement'];
        }

        return [
            'profile_id' => $profileId,
            'mode' => 'read_only_source_reconciliation',
            'transaction_cash' => ['count' => count($tradeFlows), 'total' => round(array_sum(array_column($tradeFlows, 'cash_delta')), 4), 'rows' => $tradeFlows],
            'bank_cash' => ['count' => count($bank), 'total' => round(array_sum(array_column($bank, 'cash_delta')), 4), 'rows' => $bank],
            'broker_dp_evidence' => ['count' => count($dp), 'rows' => $dp],
            'opening_balance' => $openingEvidence,
            'closing_balance' => $closing,
            'cash_event_count' => count($events),
            'broker_trade_link_count' => count($linkedTrades),
            'writes_performed' => false,
        ];
    }

    private function uniqueRows(mixed $rows, string $name): array
    {
        if (! is_array($rows)) throw new InvalidArgumentException("{$name} must be an array.");
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id']) || (string) $row['id'] === '') throw new InvalidArgumentException("Each {$name} row requires an id.");
            if (isset($ids[(string) $row['id']])) throw new InvalidArgumentException("Duplicate id in {$name}.");
            $ids[(string) $row['id']] = true;
        }
        return $rows;
    }

    private function assertProfile(array $row, int $profileId, string $label): void
    {
        if ((int) ($row['profile_id'] ?? 0) !== $profileId) throw new InvalidArgumentException("{$label} is outside the selected profile.");
    }

    private function positive(mixed $value, string $label): float
    {
        $number = is_numeric($value) ? (float) $value : 0;
        if (! is_finite($number) || $number <= 0) throw new InvalidArgumentException("{$label} must be positive.");
        return $number;
    }

    private function nonNegative(mixed $value, string $label): float
    {
        $number = is_numeric($value) ? (float) $value : -1;
        if (! is_finite($number) || $number < 0) throw new InvalidArgumentException("{$label} must be non-negative.");
        return $number;
    }

    private function date(mixed $value): string
    {
        $date = is_string($value) ? $value : '';
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        return $date;
    }
}
