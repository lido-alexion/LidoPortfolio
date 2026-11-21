<?php

namespace App\Services;

use App\Models\CashLedgerEntry;
use InvalidArgumentException;

/** Pure, exact profile 1 cleanup projection; it performs no database or filesystem I/O. */
class CashReconstructionCleanupService
{
    public const OPENING_ID = 52;
    public const OLD_OPENING = '7228613.4240';
    public const NEW_OPENING = '7228613.4225';
    public const EXPECTED_ACCOUNT = '905181.1822';
    public const OPENING_REASON = 'Derived opening balance assumption from source-linked reverse cash reconstruction; original funding unverified; excluded Zerodha broker trades; not a bank deposit';
    public const ARTIFACTS = [
        244 => ['amount' => '0.0001', 'date' => '2026-10-16', 'reason' => 'Four-decimal normalization to Zerodha post-quarterly-payout balance -0.001011; ledger scale is 4dp.'],
        245 => ['amount' => '-0.0022', 'date' => '2026-10-22', 'reason' => 'Zerodha full tracked settlement residual for 2026-10-22; reconciliation only; aggregate not copied'],
        248 => ['amount' => '0.0015', 'date' => '2026-11-13', 'reason' => 'Zerodha full tracked settlement residual for 2026-11-13; reconciliation only; aggregate not copied'],
        252 => ['amount' => '-0.0008', 'date' => '2026-11-16', 'reason' => 'Zerodha full tracked settlement residual for 2026-11-16; reconciliation only; aggregate not copied'],
        253 => ['amount' => '-0.0001', 'date' => '2026-11-16', 'reason' => 'Four-decimal cumulative normalization so posted ledger equals Zerodha closing balance rounded to 4dp; source precision 6dp.'],
    ];

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public function project(array $rows): array
    {
        if (count($rows) !== 203) throw new InvalidArgumentException('Expected exact 203-row profile 1 snapshot.');
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($byId[$id]) || (int) ($row['profile_id'] ?? 0) !== 1) throw new InvalidArgumentException('Snapshot contains duplicate/missing IDs or another profile.');
            $byId[$id] = $row;
        }
        $opening = $byId[self::OPENING_ID] ?? null;
        if (! $opening || (string) ($opening['amount'] ?? '') !== self::OLD_OPENING || ($opening['entry_type'] ?? null) !== CashLedgerEntry::TYPE_ADJUSTMENT) {
            throw new InvalidArgumentException('Opening entry differs from the exact approved snapshot.');
        }
        foreach (self::ARTIFACTS as $id => $expected) {
            $row = $byId[$id] ?? null;
            if (! $row || (string) ($row['amount'] ?? '') !== $expected['amount'] || substr((string) ($row['entry_date'] ?? ''), 0, 10) !== $expected['date']
                || (string) ($row['reason'] ?? '') !== $expected['reason'] || ($row['entry_type'] ?? null) !== CashLedgerEntry::TYPE_ADJUSTMENT
                || ($row['transaction_id'] ?? null) !== null) {
                throw new InvalidArgumentException("Artifact row {$id} differs from the exact approved snapshot.");
            }
            unset($byId[$id]);
        }
        $byId[self::OPENING_ID]['amount'] = self::NEW_OPENING;
        $byId[self::OPENING_ID]['entry_classification'] = 'derived_opening_assumption';
        $byId[self::OPENING_ID]['reason'] = self::OPENING_REASON;
        $result = array_values($byId);
        usort($result, fn ($a, $b) => [substr((string) ($a['entry_date'] ?? ''), 0, 10), (string) ($a['created_at'] ?? ''), (int) $a['id']]
            <=> [substr((string) ($b['entry_date'] ?? ''), 0, 10), (string) ($b['created_at'] ?? ''), (int) $b['id']]);
        $balance = 0.0;
        foreach ($result as &$row) {
            $balance = round($balance + (float) $row['amount'], 4);
            $row['balance_after'] = number_format($balance, 4, '.', '');
            if (($row['entry_type'] ?? null) === CashLedgerEntry::TYPE_ADJUSTMENT && abs((float) $row['amount']) < 0.01) {
                throw new InvalidArgumentException('A synthetic sub-paisa adjustment remains after cleanup.');
            }
        }
        unset($row);
        if (count($result) !== 198 || abs($balance - (float) self::EXPECTED_ACCOUNT) > 0.00005) throw new InvalidArgumentException('Projected ledger count or closing arithmetic differs from the approved source proof.');
        return $result;
    }
}
