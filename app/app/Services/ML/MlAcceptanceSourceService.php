<?php

namespace App\Services\ML;

use App\Jobs\MlAcceptanceJob;
use App\Models\V8\MlAcceptanceSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class MlAcceptanceSourceService
{
    public const MAX_BYTES = 16777216;

    public const MAX_EXPANDED = 33554432;

    public function create(array $manifest, int $actor): MlAcceptanceSource
    {
        app(MlAcceptanceRuntime::class)->assertQueue();
        validator($manifest, [
            'version' => 'required|integer|in:1',
            'source' => 'required|in:nse_mii_security_file,nse_cash_bhavcopy',
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'filename' => ['required', 'string', 'max:150', 'regex:/\A[A-Za-z0-9_.-]+\.(csv|zip)\z/i'],
            'bytes' => 'required|integer|min:1|max:'.self::MAX_BYTES,
            'sha256' => 'required|regex:/\A[a-f0-9]{64}\z/',
        ])->validate();
        $manifest = array_intersect_key($manifest, array_flip(['version', 'source', 'date', 'filename', 'bytes', 'sha256']));
        // Contemporaneous official file families, not arbitrary renamed uploads.
        $pattern = $manifest['source'] === 'nse_cash_bhavcopy'
            ? '/\A(?:cm\d{2}[A-Za-z]{3}\d{4}bhav\.csv(?:\.zip)?|BhavCopy_NSE_CM_0_0_0_\d{8}_F_0000\.csv(?:\.zip)?)\z/i'
            : '/\A(?:NSE|MII)[A-Za-z0-9_.-]*\d{8}[A-Za-z0-9_.-]*\.(csv|zip)\z/i';
        $this->require(preg_match($pattern, $manifest['filename']) === 1, 'Unsupported official source filename.');

        return Cache::lock('ml-acceptance-source-quota', 30)->block(5, function () use ($manifest, $actor) {
            $this->require(MlAcceptanceSource::query()->where('actor_id', $actor)->whereIn('status', ['uploading', 'queued'])->count() < 4, 'Incomplete upload quota exceeded.');
            $reserved = 0;
            foreach (MlAcceptanceSource::query()->cursor() as $source) {
                $reserved += (int) ($source->manifest['bytes'] ?? 0) + (int) ($source->evidence['expanded_bytes'] ?? self::MAX_EXPANDED);
            }
            $this->require($reserved + (int) $manifest['bytes'] + self::MAX_EXPANDED <= 2147483648, 'Private source quota exceeded.');
            $source = MlAcceptanceSource::query()->create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor, 'status' => 'uploading', 'manifest' => $manifest,
                'history' => app(MlAcceptanceRuntime::class)->history([], 'created', $actor),
            ]);
            File::ensureDirectoryExists($this->directory($source), 0700, true);

            return $source;
        });
    }

    public function chunk(MlAcceptanceSource $source, int $offset, string $bytes, int $actor): MlAcceptanceSource
    {
        return Cache::lock('ml-source-'.$source->id, 60)->block(5, function () use ($source, $offset, $bytes, $actor) {
            $source->refresh();
            $this->require($source->status === 'uploading', 'Source is not writable.');
            $this->require(strlen($bytes) > 0 && strlen($bytes) <= 1048576 && $offset >= 0, 'Invalid chunk size or offset.');
            $this->require($offset + strlen($bytes) <= (int) $source->manifest['bytes'], 'Declared size exceeded.');
            $path = $this->directory($source).'/payload';
            $handle = fopen($path, 'c+b');
            try {
                $size = fstat($handle)['size'];
                $this->require($offset <= $size, 'Chunk offset is not contiguous.');
                fseek($handle, $offset);
                if ($offset < $size) {
                    $this->require($offset + strlen($bytes) <= $size && fread($handle, strlen($bytes)) === $bytes, 'Duplicate chunk differs.');
                    if ($source->received === $size) {
                        return $source;
                    }
                } else {
                    $this->require(fwrite($handle, $bytes) === strlen($bytes), 'Chunk could not be stored.');
                    fflush($handle);
                }
                $source->forceFill(['received' => max($size, $offset + strlen($bytes)), 'history' => app(MlAcceptanceRuntime::class)->history($source->history, 'chunk:'.$offset.':'.strlen($bytes), $actor)])->save();
            } finally {
                fclose($handle);
            }

            return $source;
        });
    }

    public function finalize(MlAcceptanceSource $source, int $actor): MlAcceptanceSource
    {
        app(MlAcceptanceRuntime::class)->assertQueue();

        return Cache::lock('ml-source-'.$source->id, 60)->block(5, function () use ($source, $actor) {
            $source->refresh();
            $this->require($source->status === 'uploading' && $source->received === (int) $source->manifest['bytes'], 'Upload is incomplete or already finalized.');
            $source->forceFill(['status' => 'queued', 'history' => app(MlAcceptanceRuntime::class)->history($source->history, 'finalize', $actor)])->save();
            MlAcceptanceJob::dispatch('source', $source->id);

            return $source;
        });
    }

    public function resume(MlAcceptanceSource $source, int $actor): MlAcceptanceSource
    {
        app(MlAcceptanceRuntime::class)->assertQueue();

        return Cache::lock('ml-source-'.$source->id, 60)->block(5, function () use ($source, $actor) {
            $source->refresh();
            $this->require($source->status === 'queued', 'Only queued validation can resume.');
            $source->forceFill(['history' => app(MlAcceptanceRuntime::class)->history($source->history, 'validation_resumed', $actor)])->save();
            MlAcceptanceJob::dispatch('source', $source->id);

            return $source;
        });
    }

    public function validateQueued(string $id): void
    {
        Cache::lock('ml-source-'.$id, MlAcceptanceRuntime::LOCK_SECONDS)->block(5, function () use ($id) {
            $source = MlAcceptanceSource::query()->findOrFail($id);
            if ($source->status !== 'queued') {
                return;
            }
            try {
                $manifest = $source->manifest;
                $payload = $this->directory($source).'/payload';
                $this->require(is_file($payload) && filesize($payload) === (int) $manifest['bytes'] && hash_equals($manifest['sha256'], hash_file('sha256', $payload)), 'Source size or SHA-256 mismatch.');
                $contents = $this->safeContents($payload, $manifest['filename'], $manifest['date']);
                $filename = preg_replace('/\.zip$/i', '', $manifest['filename']);
                if (! str_ends_with(strtolower($filename), '.csv')) {
                    $filename .= '.csv';
                }
                $path = $this->directory($source).'/'.$filename;
                $this->require(file_put_contents($path, $contents) === strlen($contents), 'Source could not be stored.');
                $snapshot = app(NseHistoricalUniverseArchiveProvider::class)->stagedSnapshot($path, $manifest['source'], $manifest['date'], false);
                chmod($path, 0400);
                chmod($payload, 0400);
                $source->forceFill(['status' => 'sealed', 'evidence' => [
                    'filename' => $filename, 'expanded_bytes' => strlen($contents), 'content_sha256' => hash('sha256', $contents),
                    'validated_date' => $snapshot['diagnostics']['source_validated_date'],
                    'format_version' => $snapshot['diagnostics']['format_version'],
                    'parser_version' => NseHistoricalUniverseArchiveProvider::PARSER_VERSION,
                ], 'history' => app(MlAcceptanceRuntime::class)->history($source->history, 'sealed', null)])->save();
            } catch (\Throwable $e) {
                $source->forceFill(['status' => 'failed', 'evidence' => ['error' => 'source_validation_failed'], 'history' => app(MlAcceptanceRuntime::class)->history($source->history, 'validation_failed', null)])->save();
                // Payload is retained privately for audit; responses never expose parser errors/host paths.
            }
        });
    }

    public function safeContents(string $path, string $filename, ?string $date = null): string
    {
        if (! str_ends_with(strtolower($filename), '.zip')) {
            return (string) file_get_contents($path);
        }
        $zip = new ZipArchive;
        $this->require($zip->open($path, ZipArchive::CHECKCONS) === true, 'Invalid archive.');
        try {
            $this->require($zip->numFiles === 1, 'Archive must contain one flat CSV.');
            $stat = $zip->statIndex(0);
            $this->require(is_array($stat), 'Invalid archive entry.');
            $name = $stat['name'];
            $this->require(preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9_.-]*\.csv\z/i', $name) === 1 && ! str_contains($name, '..'), 'Unsafe archive entry.');
            $opsys = $attr = 0;
            $zip->getExternalAttributesIndex(0, $opsys, $attr);
            $type = ($attr >> 16) & 0170000;
            $this->require(in_array($type, [0, 0100000], true) && ($stat['encryption_method'] ?? 0) === 0, 'Links or encrypted entries are prohibited.');
            $this->require($stat['size'] > 0 && $stat['size'] <= self::MAX_EXPANDED && $stat['size'] <= max(1, $stat['comp_size']) * 100, 'Archive expansion limit exceeded.');
            $contents = $zip->getFromIndex(0, self::MAX_EXPANDED + 1);
            $this->require(is_string($contents) && strlen($contents) === $stat['size'] && sprintf('%u', crc32($contents)) === sprintf('%u', $stat['crc']), 'Archive checksum failed.');
            if ($date !== null) {
                app(NseHistoricalUniverseArchiveProvider::class)->validateEntryDate($name, $contents, $date);
            }

            return $contents;
        } finally {
            $zip->close();
        }
    }

    /**
     * Seal an automatically downloaded official NSE archive as immutable
     * acceptance evidence for the campaign-independent forward collector.
     * Actor 0 is the reserved system actor; no human identity is fabricated.
     */
    public function sealOfficialAcquisition(string $archivePath, array $descriptor, string $date): MlAcceptanceSource
    {
        $filename = (string) ($descriptor['filename'] ?? '');
        $url = (string) ($descriptor['url'] ?? '');
        $this->require(preg_match('/\A(?:cm\d{2}[A-Za-z]{3}\d{4}bhav\.csv\.zip|BhavCopy_NSE_CM_0_0_0_\d{8}_F_0000\.csv\.zip)\z/i', $filename) === 1, 'Unsupported official archive filename.');
        $this->require(parse_url($url, PHP_URL_SCHEME) === 'https', 'Official source URL must use HTTPS.');
        $this->require(is_file($archivePath) && filesize($archivePath) > 0 && filesize($archivePath) <= self::MAX_BYTES, 'Official archive size is invalid.');
        $sha256 = hash_file('sha256', $archivePath);
        $this->require(is_string($sha256) && preg_match('/\A[a-f0-9]{64}\z/', $sha256) === 1, 'Official archive hash is invalid.');

        $existing = MlAcceptanceSource::query()
            ->where('manifest->sha256', $sha256)
            ->where('manifest->date', $date)
            ->where('manifest->source', 'nse_cash_bhavcopy')
            ->first();
        if ($existing !== null) {
            $this->require($existing->status === 'sealed', 'Existing official source is not sealed.');
            return $existing;
        }

        $contents = $this->safeContents($archivePath, $filename, $date);
        $id = (string) Str::uuid();
        $directory = storage_path('app/private/ml-acceptance/sources/'.$id);
        File::ensureDirectoryExists($directory, 0700, true);
        $storedArchive = $directory.'/payload';
        $storedCsvName = preg_replace('/\.zip$/i', '', $filename);
        $storedCsv = $directory.'/'.$storedCsvName;
        $this->require(copy($archivePath, $storedArchive), 'Official archive could not be retained.');
        $this->require(file_put_contents($storedCsv, $contents, LOCK_EX) === strlen($contents), 'Official CSV could not be retained.');
        try {
            $snapshot = app(NseHistoricalUniverseArchiveProvider::class)->stagedSnapshot($storedCsv, 'nse_cash_bhavcopy', $date);
            chmod($storedArchive, 0400);
            chmod($storedCsv, 0400);
            return MlAcceptanceSource::query()->create([
                'id' => $id,
                'actor_id' => 0,
                'status' => 'sealed',
                'manifest' => [
                    'version' => 1, 'source' => 'nse_cash_bhavcopy', 'date' => $date,
                    'filename' => $filename, 'bytes' => filesize($storedArchive), 'sha256' => $sha256,
                ],
                'received' => filesize($storedArchive),
                'evidence' => [
                    'filename' => $storedCsvName, 'expanded_bytes' => strlen($contents),
                    'content_sha256' => hash('sha256', $contents),
                    'validated_date' => $snapshot['diagnostics']['source_validated_date'],
                    'format_version' => $snapshot['diagnostics']['format_version'],
                    'parser_version' => NseHistoricalUniverseArchiveProvider::PARSER_VERSION,
                    'archive_url' => $url, 'archive_sha256' => $sha256,
                ],
                'history' => app(MlAcceptanceRuntime::class)->history([], 'system_official_source_sealed', null),
            ]);
        } catch (\Throwable $error) {
            @unlink($storedArchive);
            @unlink($storedCsv);
            @rmdir($directory);
            throw $error;
        }
    }

    public function snapshot(MlAcceptanceSource $source): array
    {
        $this->require($source->status === 'sealed', 'Source is not sealed.');
        $path = $this->directory($source).'/'.$source->evidence['filename'];
        $this->require(is_file($path) && hash_equals($source->evidence['content_sha256'], hash_file('sha256', $path)), 'Sealed source integrity failed.');
        $snapshot = app(NseHistoricalUniverseArchiveProvider::class)->stagedSnapshot($path, $source->manifest['source'], $source->manifest['date']);
        $snapshot['snapshot_key'] = 'sha256:'.$source->manifest['sha256'];
        unset($snapshot['diagnostics']['nse_source_file']);
        $snapshot['diagnostics']['source_id'] = $source->id;
        $snapshot['diagnostics']['source_sha256'] = $source->manifest['sha256'];
        $snapshot['diagnostics']['membership_sha256'] = hash('sha256', json_encode($snapshot['memberships'], JSON_THROW_ON_ERROR));

        return $snapshot;
    }

    public function cancel(MlAcceptanceSource $source, int $actor): MlAcceptanceSource
    {
        return Cache::lock('ml-source-'.$source->id, 60)->block(5, function () use ($source, $actor) {
            $source->refresh();
            $this->require(in_array($source->status, ['uploading', 'queued'], true), 'Source cannot be cancelled.');
            $source->forceFill(['status' => 'cancelled', 'history' => app(MlAcceptanceRuntime::class)->history($source->history, 'cancelled', $actor)])->save();

            return $source;
        });
    }

    public function directory(MlAcceptanceSource $source): string
    {
        return storage_path('app/private/ml-acceptance/sources/'.$source->id);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['source' => [$message]]);
        }
    }
}
