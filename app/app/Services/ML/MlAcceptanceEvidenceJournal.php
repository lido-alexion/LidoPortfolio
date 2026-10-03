<?php

namespace App\Services\ML;

use App\Exceptions\MlAcceptanceEvidenceQuotaExceeded;
use App\Exceptions\MlAcceptanceEvidenceIoFailed;

class MlAcceptanceEvidenceJournal
{
    public const MAX_BYTES = 536870912;

    private mixed $stream = null;

    private \DeflateContext $compression;

    private \HashContext $contentHash;

    private int $bytes = 0;

    private int $records = 0;

    private bool $failed = false;

    public function __construct(private readonly string $path, private readonly int $maxBytes = self::MAX_BYTES)
    {
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Invalid PIT evidence quota.');
        }
        $compression = $this->io(fn () => deflate_init(ZLIB_ENCODING_GZIP));
        if ($compression === false) {
            throw new \RuntimeException('PIT evidence compression unavailable.');
        }
        $this->compression = $compression;
        $this->contentHash = hash_init('sha256');
        $this->stream = $this->io(fn () => fopen($path, 'wb'));
        if ($this->stream === false) {
            throw new MlAcceptanceEvidenceIoFailed;
        }
    }

    public function append(array $record): void
    {
        $this->requireOpen();
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
            $compressed = $this->io(fn () => $this->compress($line, ZLIB_NO_FLUSH));
            if ($compressed === false) {
                throw new MlAcceptanceEvidenceIoFailed;
            }
            $this->write($compressed);
            hash_update($this->contentHash, $line);
            $this->records++;
        } catch (\Throwable $error) {
            $this->failed = true;
            // Never retain an exception from user-supplied serialization or a stream.
            throw $error instanceof MlAcceptanceEvidenceQuotaExceeded || $error instanceof MlAcceptanceEvidenceIoFailed
                ? $error : new \RuntimeException('PIT evidence serialization failed.');
        }
    }

    public function finish(): array
    {
        $this->requireOpen();
        try {
            $compressed = $this->io(fn () => $this->compress('', ZLIB_FINISH));
            if ($compressed === false) {
                throw new MlAcceptanceEvidenceIoFailed;
            }
            $this->write($compressed);
            if (! $this->io(fn () => $this->flushStream($this->stream))) {
                throw new MlAcceptanceEvidenceIoFailed;
            }
            $closed = $this->io(fn () => $this->closeStream($this->stream));
            $this->stream = null;
            if (! $closed) {
                throw new MlAcceptanceEvidenceIoFailed;
            }
            $artifactHash = $this->io(fn () => hash_file('sha256', $this->path));
            if ($artifactHash === false) {
                throw new MlAcceptanceEvidenceIoFailed;
            }

            return ['pit_evidence_format' => 'jsonl-gzip-v1', 'pit_evidence_sha256' => $artifactHash,
                'pit_evidence_content_sha256' => hash_final($this->contentHash),
                'pit_evidence_bytes' => $this->bytes, 'pit_evidence_records' => $this->records];
        } catch (\Throwable $error) {
            $this->failed = true;
            throw $error instanceof MlAcceptanceEvidenceQuotaExceeded || $error instanceof MlAcceptanceEvidenceIoFailed
                ? $error : new MlAcceptanceEvidenceIoFailed;
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            // Cleanup preserves partial evidence and cannot mask the original failure.
            try {
                if (! $this->io(fn () => $this->closeStream($this->stream))) {
                    $this->failed = true;
                }
            } catch (\Throwable) {
                $this->failed = true;
            }
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function requireOpen(): void
    {
        if ($this->failed || ! is_resource($this->stream)) {
            throw new \LogicException('PIT evidence journal is closed or incomplete.');
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
            $written = $this->io(fn () => $this->writeChunk($this->stream, substr($compressed, $offset)));
            if ($written === false || $written === 0) {
                throw new MlAcceptanceEvidenceIoFailed;
            }
            $offset += $written;
            $this->bytes += $written;
        }
    }

    /** Convert filesystem warnings and stream exceptions to a payload-free diagnostic. */
    private function io(callable $operation): mixed
    {
        set_error_handler(static function (): never {
            throw new MlAcceptanceEvidenceIoFailed;
        });
        try {
            return $operation();
        } catch (\Throwable) {
            throw new MlAcceptanceEvidenceIoFailed;
        } finally {
            restore_error_handler();
        }
    }

    protected function compress(string $data, int $mode): string|false
    {
        return deflate_add($this->compression, $data, $mode);
    }

    protected function writeChunk(mixed $stream, string $data): int|false
    {
        return fwrite($stream, $data);
    }

    protected function flushStream(mixed $stream): bool
    {
        return fflush($stream);
    }

    protected function closeStream(mixed $stream): bool
    {
        return fclose($stream);
    }
}
