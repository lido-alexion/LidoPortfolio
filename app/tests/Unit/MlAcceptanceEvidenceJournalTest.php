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
    public function test_exact_stored_boundary_and_one_byte_less_for_inventory_and_row(): void
    {
        foreach (['fact_inventory', 'fundamentals'] as $key) {
            $record = ['stock_id' => 1, $key => ['private_fact' => 'value']];
            $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
            $compression = deflate_init(ZLIB_ENCODING_GZIP);
            $expected = deflate_add($compression, $line, ZLIB_NO_FLUSH).deflate_add($compression, '', ZLIB_FINISH);
            $path = $this->directory.'/'.$key;
            $journal = new MlAcceptanceEvidenceJournal($path, strlen($expected));
            $journal->append($record);
            $metadata = $journal->finish();
            $this->assertSame($expected, file_get_contents($path));
            $this->assertSame(hash('sha256', $expected), $metadata['pit_evidence_sha256']);
            $this->assertSame(hash('sha256', $line), $metadata['pit_evidence_content_sha256']);

            $partial = $path.'-partial';
            $journal = new MlAcceptanceEvidenceJournal($partial, strlen($expected) - 1);
            $journal->append($record);
            $before = file_get_contents($partial);
            try {
                $journal->finish();
                $this->fail('Footer exceeded the stored-byte boundary.');
            } catch (MlAcceptanceEvidenceQuotaExceeded) {
                $this->assertSame($before, file_get_contents($partial));
            }
            $this->assertCannotFinish($journal);
            $journal->close();
            $this->assertFileExists($partial);
        }
    }

    public function test_partial_writes_are_completed_without_changing_the_artifact(): void
    {
        $path = $this->directory.'/short';
        $journal = new FaultingEvidenceJournal($path);
        $journal->fault = 'short';
        $record = ['fact_inventory' => ['private_fact' => 123]];
        $journal->append($record);
        $metadata = $journal->finish();
        $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
        $this->assertSame($line, gzdecode(file_get_contents($path)));
        $this->assertSame(hash('sha256', $line), $metadata['pit_evidence_content_sha256']);
        $this->assertSame(hash_file('sha256', $path), $metadata['pit_evidence_sha256']);
        $this->assertSame(filesize($path), $metadata['pit_evidence_bytes']);
    }

    public function test_io_failures_are_safe_terminal_and_retain_partial_evidence(): void
    {
        foreach (['write', 'zero', 'warning', 'throw', 'flush', 'close', 'compress'] as $fault) {
            $path = $this->directory.'/'.$fault;
            $journal = new FaultingEvidenceJournal($path);
            $journal->append(['stock_id' => 1]);
            $journal->fault = $fault;
            try {
                $journal->finish();
                $this->fail('Failed I/O produced completion metadata: '.$fault);
            } catch (\App\Exceptions\MlAcceptanceEvidenceIoFailed $error) {
                $this->assertSame('PIT evidence journal I/O failed.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
            $journal->fault = '';
            $this->assertCannotFinish($journal);
            $journal->close();
            $this->assertFileExists($path);
        }
    }

    public function test_failed_serialization_cannot_be_skipped_and_then_completed(): void
    {
        $journal = new MlAcceptanceEvidenceJournal($this->directory.'/serialization');
        $journal->append(['stock_id' => 1]);
        $payload = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('/private/path secret raw fact');
            }
        };
        try {
            $journal->append(['fact_inventory' => $payload]);
            $this->fail('Invalid serialization accepted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('PIT evidence serialization failed.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertCannotFinish($journal);
        $journal->close();
    }

    public function test_open_failure_does_not_emit_path_bearing_warnings(): void
    {
        $this->expectException(\App\Exceptions\MlAcceptanceEvidenceIoFailed::class);
        $this->expectExceptionMessage('PIT evidence journal I/O failed.');
        new MlAcceptanceEvidenceJournal($this->directory.'/private-secret/missing');
    }

    private function assertCannotFinish(MlAcceptanceEvidenceJournal $journal): void
    {
        foreach (['append', 'finish'] as $action) {
            try {
                $action === 'append' ? $journal->append(['stock_id' => 2]) : $journal->finish();
                $this->fail('Incomplete journal accepted '.$action);
            } catch (\LogicException $error) {
                $this->assertSame('PIT evidence journal is closed or incomplete.', $error->getMessage());
            }
        }
    }

}


/** Faults occur at the native I/O boundary; all production bookkeeping still runs. */
class FaultingEvidenceJournal extends MlAcceptanceEvidenceJournal
{
    public string $fault = '';

    protected function writeChunk(mixed $stream, string $data): int|false
    {
        if ($this->fault === 'write') return false;
        if ($this->fault === 'zero') return 0;
        if ($this->fault === 'warning') trigger_error('/private/path secret raw fact', E_USER_WARNING);
        if ($this->fault === 'throw') throw new \RuntimeException('/private/path secret raw fact');
        return parent::writeChunk($stream, $this->fault === 'short' ? substr($data, 0, 3) : $data);
    }

    protected function flushStream(mixed $stream): bool
    {
        return $this->fault === 'flush' ? false : parent::flushStream($stream);
    }

    protected function closeStream(mixed $stream): bool
    {
        $closed = parent::closeStream($stream);
        return $this->fault === 'close' ? false : $closed;
    }

    protected function compress(string $data, int $mode): string|false
    {
        return $this->fault === 'compress' ? false : parent::compress($data, $mode);
    }
}
