<?php

namespace App\Services\ML;

use App\Exceptions\MlAcceptanceEvidenceQuotaExceeded;

class MlAcceptanceEvidenceJournal
{
    public const MAX_BYTES = 536870912;

    private mixed $stream = null;

    private \DeflateContext $compression;

    private \HashContext $contentHash;

    private int $bytes = 0;

    private int $records = 0;

    public function __construct(private readonly string $path, private readonly int $maxBytes = self::MAX_BYTES)
    {
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Invalid PIT evidence quota.');
        }
        $compression = deflate_init(ZLIB_ENCODING_GZIP);
        if ($compression === false) {
            throw new \RuntimeException('PIT evidence compression unavailable.');
        }
        $this->compression = $compression;
        $this->contentHash = hash_init('sha256');
        $this->stream = fopen($path, 'wb');
        if ($this->stream === false) {
            throw new \RuntimeException('PIT evidence journal unavailable.');
        }
    }

    public function append(array $record): void
    {
        $this->requireOpen();
        $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
        $compressed = deflate_add($this->compression, $line, ZLIB_NO_FLUSH);
        if ($compressed === false) {
            throw new \RuntimeException('PIT evidence compression failed.');
        }
        $this->write($compressed);
        hash_update($this->contentHash, $line);
        $this->records++;
    }

    public function finish(): array
    {
        $this->requireOpen();
        $compressed = deflate_add($this->compression, '', ZLIB_FINISH);
        if ($compressed === false) {
            throw new \RuntimeException('PIT evidence compression failed.');
        }
        $this->write($compressed);
        $closed = fclose($this->stream);
        $this->stream = null;
        if (! $closed) {
            throw new \RuntimeException('PIT evidence journal finalization failed.');
        }
        $artifactHash = hash_file('sha256', $this->path);
        if ($artifactHash === false) {
            throw new \RuntimeException('PIT evidence journal verification failed.');
        }

        return ['pit_evidence_format' => 'jsonl-gzip-v1', 'pit_evidence_sha256' => $artifactHash,
            'pit_evidence_content_sha256' => hash_final($this->contentHash),
            'pit_evidence_bytes' => $this->bytes, 'pit_evidence_records' => $this->records];
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function requireOpen(): void
    {
        if (! is_resource($this->stream)) {
            throw new \LogicException('PIT evidence journal is closed.');
        }
    }

    private function write(string $compressed): void
    {
        $length = strlen($compressed);
        if ($length > $this->maxBytes - $this->bytes) {
            throw new MlAcceptanceEvidenceQuotaExceeded;
        }
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($this->stream, substr($compressed, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('PIT evidence journal write failed.');
            }
            $offset += $written;
            $this->bytes += $written;
        }
    }
}
