<?php

namespace Tests\Unit;

use App\Exceptions\MlAcceptanceEvidenceQuotaExceeded;
use App\Services\ML\MlAcceptanceEvidenceJournal;
use PHPUnit\Framework\TestCase;

class MlAcceptanceEvidenceJournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ml-evidence-journal-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function test_compression_preserves_every_record_and_both_content_and_artifact_hashes(): void
    {
        $path = $this->directory.'/pit-evidence.jsonl.gz';
        $journal = new MlAcceptanceEvidenceJournal($path, 16384);
        $expected = '';
        for ($id = 1; $id <= 1000; $id++) {
            $row = ['stock_id' => $id, 'reference_date' => '2026-03-31', 'partition' => 'train',
                'fundamentals' => array_fill_keys(['roe', 'debt_equity', 'eps_growth_yoy', 'net_margin'],
                    ['value_available' => false, 'available_pit_fact_ids' => []])];
            $expected .= json_encode($row, JSON_THROW_ON_ERROR)."\n";
            $journal->append($row);
        }
        $metadata = $journal->finish();
        $this->assertGreaterThan(16384, strlen($expected));
        $this->assertLessThanOrEqual(16384, filesize($path));
        $this->assertSame($expected, gzdecode(file_get_contents($path)));
        $this->assertSame('jsonl-gzip-v1', $metadata['pit_evidence_format']);
        $this->assertSame(hash('sha256', $expected), $metadata['pit_evidence_content_sha256']);
        $this->assertSame(hash_file('sha256', $path), $metadata['pit_evidence_sha256']);
        $this->assertSame(filesize($path), $metadata['pit_evidence_bytes']);
        $this->assertSame(1000, $metadata['pit_evidence_records']);
    }

    public function test_incompressible_record_fails_before_the_storage_quota_is_exceeded(): void
    {
        $path = $this->directory.'/quota.jsonl.gz';
        $journal = new MlAcceptanceEvidenceJournal($path, 1024);
        $payload = '';
        for ($id = 0; $id < 3000; $id++) {
            $payload .= hash('sha256', (string) $id);
        }
        try {
            $journal->append(['fact_inventory' => $payload]);
            $journal->finish();
            $this->fail('Oversized compressed evidence was accepted.');
        } catch (MlAcceptanceEvidenceQuotaExceeded $e) {
            $this->assertSame('PIT evidence quota exceeded.', $e->getMessage());
        } finally {
            $journal->close();
        }
        clearstatcache(true, $path);
        $this->assertLessThanOrEqual(1024, filesize($path));
    }

    public function test_final_compression_bytes_are_also_checked_against_the_quota(): void
    {
        $path = $this->directory.'/footer.jsonl.gz';
        $journal = new MlAcceptanceEvidenceJournal($path, 10);
        try {
            $journal->append(['stock_id' => 1]);
            $journal->finish();
            $this->fail('The compression footer bypassed the quota.');
        } catch (MlAcceptanceEvidenceQuotaExceeded) {
            $this->assertTrue(true);
        } finally {
            $journal->close();
        }
        clearstatcache(true, $path);
        $this->assertLessThanOrEqual(10, filesize($path));
    }

    public function test_a_caller_cannot_raise_the_frozen_storage_quota(): void
    {
        $path = $this->directory.'/invalid.jsonl.gz';
        try {
            new MlAcceptanceEvidenceJournal($path, MlAcceptanceEvidenceJournal::MAX_BYTES + 1);
            $this->fail('A larger storage quota was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertFileDoesNotExist($path);
        }
    }
}
