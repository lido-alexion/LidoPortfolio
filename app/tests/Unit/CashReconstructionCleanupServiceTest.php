<?php

namespace Tests\Unit;

use App\Models\CashLedgerEntry;
use App\Services\CashReconstructionCleanupService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CashReconstructionCleanupServiceTest extends TestCase
{
    public function test_exact_projection_leaves_198_entries_without_synthetic_micros(): void
    {
        $rows = $this->snapshot();
        $projected = (new CashReconstructionCleanupService)->project($rows);

        $this->assertCount(198, $projected);
        $ids = array_column($projected, 'id');
        foreach (array_keys(CashReconstructionCleanupService::ARTIFACTS) as $id) $this->assertNotContains($id, $ids);
        $this->assertSame('derived_opening_assumption', $projected[0]['entry_classification']);
        $this->assertSame(CashReconstructionCleanupService::NEW_OPENING, $projected[0]['amount']);
        $this->assertSame(CashReconstructionCleanupService::EXPECTED_ACCOUNT, $projected[array_key_last($projected)]['balance_after']);
        foreach ($projected as $row) {
            if ($row['entry_type'] === CashLedgerEntry::TYPE_ADJUSTMENT) $this->assertGreaterThanOrEqual(0.01, abs((float) $row['amount']));
        }
    }

    public function test_projection_fails_closed_if_one_artifact_differs(): void
    {
        $rows = $this->snapshot();
        foreach ($rows as &$row) if ((int) $row['id'] === 245) $row['amount'] = '0.0001';
        unset($row);
        $this->expectException(InvalidArgumentException::class);
        (new CashReconstructionCleanupService)->project($rows);
    }

    private function snapshot(): array
    {
        $rows = [];
        $artifactRows = [];
        foreach (CashReconstructionCleanupService::ARTIFACTS as $id => $artifact) {
            $artifactRows[] = ['id' => $id, 'profile_id' => 1, 'entry_type' => CashLedgerEntry::TYPE_ADJUSTMENT,
                'amount' => $artifact['amount'], 'balance_after' => '0.0000', 'reason' => $artifact['reason'],
                'entry_date' => $artifact['date'], 'transaction_id' => null, 'created_at' => $artifact['date'].' 12:00:00'];
        }
        $rows[] = ['id' => 52, 'profile_id' => 1, 'entry_type' => CashLedgerEntry::TYPE_ADJUSTMENT,
            'amount' => CashReconstructionCleanupService::OLD_OPENING, 'balance_after' => '0.0000',
            'reason' => 'Synthetic opening adjustment', 'entry_date' => '2024-06-04', 'transaction_id' => null, 'created_at' => '2024-06-04 12:00:00'];
        $rows = [...$rows, ...$artifactRows];
        for ($i = 0; $i < 197; $i++) {
            $rows[] = ['id' => 1000 + $i, 'profile_id' => 1, 'entry_type' => $i === 0 ? CashLedgerEntry::TYPE_WITHDRAWAL : CashLedgerEntry::TYPE_DEPOSIT,
                'amount' => $i === 0 ? '-6323432.2403' : '0.0000', 'balance_after' => '0.0000', 'reason' => 'source event',
                'entry_date' => sprintf('2025-01-%02d', ($i % 28) + 1), 'transaction_id' => null, 'created_at' => '2025-01-01 12:00:00'];
        }
        return $rows;
    }
}
