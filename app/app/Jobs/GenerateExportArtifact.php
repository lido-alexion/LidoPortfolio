<?php

namespace App\Jobs;

use App\Models\ExportArtifact;
use App\Models\ExportNotificationOutbox;
use App\Models\PortfolioProfile;
use App\Services\Export\ExportCancelledException;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\Export\ExportFileWriter;
use App\Services\Export\ExportStaleSelectionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateExportArtifact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $artifactId, public array $definition) {}

    public function handle(ExportFileWriter $writer, ExportDatasetRegistry $datasets): void
    {
        $artifact = ExportArtifact::find($this->artifactId);
        if (! $artifact || $artifact->cancelled_at || in_array($artifact->status, ['ready', 'failed', 'cancelled'], true)) return;
        if (! ExportArtifact::query()->whereKey($artifact->id)->where('status', 'queued')->whereNull('cancelled_at')->update(['status' => 'running'])) return;

        $temporary = $artifact->path.'.partial';
        $path = Storage::disk('local')->path($temporary);
        $finalPath = Storage::disk('local')->path($artifact->path);
        Storage::disk('local')->makeDirectory(dirname($artifact->path));
        $promoted = false;

        try {
            $userId = $artifact->user_id;
            $profile = PortfolioProfile::query()->where('user_id', $userId)->findOrFail($this->definition['profile_id']);
            $shouldCancel = fn () => ! ExportArtifact::query()->whereKey($artifact->id)->where('status', 'running')->whereNull('cancelled_at')->exists();
            if (isset($this->definition['basket_items'])) {
                $sheets = [];
                $totalRows = 0;
                $totalFields = 0;
                foreach ($this->definition['basket_items'] as $item) {
                    $datasets->assertAuthorized($item['dataset'], $profile, (int) $userId);
                    $selected = ($item['scope'] ?? null) === 'selected' ? ($item['selected'] ?? []) : [];
                    $resolved = $datasets->resolveForWorker($item['dataset'], $profile, $item['filters'] ?? [], $selected);
                    $columns = $item['fields'];
                    if (array_diff($columns, $resolved['columns'])) throw new \RuntimeException('An export field is no longer available.');
                    $rows = $resolved['rows'];
                    if ($selected !== [] && empty($resolved['selection_validated'])) {
                        if (! isset($resolved['identities']) || array_diff($selected, $resolved['identities'])) throw new ExportStaleSelectionException('Selected export rows are stale or unavailable.');
                        $identityRows = array_combine($resolved['identities'], $rows);
                        $rows = array_map(fn ($identity) => $identityRows[$identity], $selected);
                    }
                    $itemRows = $resolved['estimated_rows'] ?? count($rows);
                    $totalRows += $itemRows;
                    $totalFields += count($columns);
                    if ($totalRows > config('exports.max_rows')) throw new \RuntimeException('Export exceeds the maximum row count.');
                    if ($totalFields > config('exports.max_fields')) throw new \RuntimeException('Export exceeds the maximum field count.');
                    $metadata = [...($this->definition['metadata'] ?? []), ...($resolved['metadata'] ?? []), 'scope' => $item['scope'], 'fields' => $columns, 'exported_at' => now()->toIso8601String()];
                    $sheets[] = ['name' => $item['sheet_name'] ?? $item['dataset'], 'columns' => $columns, 'rows' => $rows, 'row_count' => $itemRows, 'metadata' => $metadata];
                }
                $writer->xlsx($sheets, $path, $shouldCancel);
            } else {
                $datasets->assertAuthorized($this->definition['dataset'], $profile, (int) $userId);
                $selected = ($this->definition['scope'] ?? null) === 'selected' ? ($this->definition['selected'] ?? []) : [];
                $resolved = $datasets->resolveForWorker($this->definition['dataset'], $profile, $this->definition['filters'] ?? [], $selected);
                $columns = $this->definition['columns'];
                if (array_diff($columns, $resolved['columns'])) throw new \RuntimeException('An export field is no longer available.');
                $rows = $resolved['rows'];
                if ($selected !== [] && empty($resolved['selection_validated'])) {
                    if (! isset($resolved['identities']) || array_diff($selected, $resolved['identities'])) throw new ExportStaleSelectionException('Selected export rows are stale or unavailable.');
                    $identityRows = array_combine($resolved['identities'], $rows);
                    $rows = array_map(fn ($identity) => $identityRows[$identity], $selected);
                }
                $rowCount = $resolved['estimated_rows'] ?? count($rows);
                if ($rowCount > config('exports.max_rows')) throw new \RuntimeException('Export exceeds the maximum row count.');
                $metadata = [...($this->definition['metadata'] ?? []), ...($resolved['metadata'] ?? []), 'exported_at' => now()->toIso8601String()];
                if ($artifact->format === 'csv') $writer->csv($columns, $rows, $path, $metadata, $shouldCancel, $rowCount);
                else $writer->xlsx([['name' => 'Data', 'columns' => $columns, 'rows' => $rows, 'row_count' => $rowCount, 'metadata' => $metadata]], $path, $shouldCancel);
            }
            clearstatcache(true, $path);
            if (! rename($path, $finalPath)) throw new \RuntimeException('Unable to finalize export artifact.');

            $outboxId = DB::transaction(function () use ($artifact): ?int {
                $updated = ExportArtifact::query()
                    ->whereKey($artifact->id)
                    ->where('status', 'running')
                    ->whereNull('cancelled_at')
                    ->update(['status' => 'ready', 'expires_at' => now()->addDay()]);
                if ($updated !== 1) return null;

                return ExportNotificationOutbox::query()->firstOrCreate(
                    ['artifact_id' => $artifact->id, 'event_type' => 'completed'],
                )->id;
            });
            if ($outboxId === null) {
                @unlink($finalPath);
                if (is_file($path)) @unlink($path);
                return;
            }

            // The durable outbox row committed with the ready transition. If
            // queue dispatch fails, the scheduled outbox sweep will retry it.
            $promoted = true;
            DeliverExportNotificationOutbox::dispatch($outboxId)->onQueue('notifications');
        } catch (ExportCancelledException) {
            if (is_file($path)) @unlink($path);
            if (is_file($finalPath)) @unlink($finalPath);
            return;
        } catch (Throwable $error) {
            if (! $promoted) {
                if (is_file($path)) @unlink($path);
                if (is_file($finalPath)) @unlink($finalPath);

                $failure = $error instanceof ExportStaleSelectionException
                    ? ['code' => 'selected_rows_stale', 'message' => 'Some selected rows are no longer available. Update the selection and start a new export.']
                    : ['code' => 'generation_failed', 'message' => 'Export could not be generated. Refresh the data and try again.'];
                $metadata = [...($artifact->metadata ?? []), 'failure' => $failure];

                $outboxId = DB::transaction(function () use ($artifact, $metadata): ?int {
                    $updated = ExportArtifact::query()
                        ->whereKey($artifact->id)
                        ->where('status', 'running')
                        ->whereNull('cancelled_at')
                        ->update(['status' => 'failed', 'expires_at' => now()->addDay(), 'metadata' => $metadata]);
                    if ($updated !== 1) return null;

                    return ExportNotificationOutbox::query()->firstOrCreate(
                        ['artifact_id' => $artifact->id, 'event_type' => 'failed'],
                    )->id;
                });
                if ($outboxId !== null) DeliverExportNotificationOutbox::dispatch($outboxId)->onQueue('notifications');
            }

            throw $error;
        }
    }
}
