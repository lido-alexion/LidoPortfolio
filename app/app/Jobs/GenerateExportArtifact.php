<?php

namespace App\Jobs;

use App\Models\ExportArtifact;
use App\Models\PortfolioProfile;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\Export\ExportCancelledException;
use App\Services\Export\ExportFileWriter;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateExportArtifact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $artifactId, public array $definition) {}

    public function handle(ExportFileWriter $writer, NotificationPublisher $notifications, ExportDatasetRegistry $datasets): void
    {
        $artifact = ExportArtifact::find($this->artifactId);
        if (! $artifact || $artifact->cancelled_at || $artifact->status === 'ready') return;
        if (! ExportArtifact::query()->whereKey($artifact->id)->where('status', 'queued')->whereNull('cancelled_at')->update(['status' => 'running'])) return;
        $temporary = $artifact->path.'.partial';
        $path = Storage::disk('local')->path($temporary);
        Storage::disk('local')->makeDirectory(dirname($artifact->path));
        try {
            $userId = $artifact->user_id;
            $profile = PortfolioProfile::query()->where('user_id', $userId)->findOrFail($this->definition['profile_id']);
            $datasets->assertAuthorized($this->definition['dataset'], $profile, (int) $userId);
            $resolved = $datasets->resolve($this->definition['dataset'], $profile);
            $columns = $this->definition['columns'];
            if (array_diff($columns, $resolved['columns'])) throw new \RuntimeException('An export field is no longer available.');
            $rows = $resolved['rows'];
            if (($this->definition['scope'] ?? null) === 'selected') {
                $selected = $this->definition['selected'] ?? [];
                if (! isset($resolved['identities']) || array_diff($selected, $resolved['identities'])) throw new \RuntimeException('Selected export rows are stale or unavailable.');
                $identityRows = array_combine($resolved['identities'], $rows);
                $rows = array_map(fn ($identity) => $identityRows[$identity], $selected);
            }
            if (count($rows) > config('exports.max_rows')) throw new \RuntimeException('Export exceeds the maximum row count.');
            $metadata = [...($this->definition['metadata'] ?? []), ...($resolved['metadata'] ?? []), 'exported_at' => now()->toIso8601String()];
            $shouldCancel = fn () => ! ExportArtifact::query()->whereKey($artifact->id)->where('status', 'running')->whereNull('cancelled_at')->exists();
            if ($artifact->format === 'csv') $writer->csv($columns, $rows, $path, $metadata, $shouldCancel);
            else $writer->xlsx([['name' => 'Data', 'columns' => $columns, 'rows' => $rows, 'metadata' => $metadata]], $path, $shouldCancel);
            clearstatcache(true, $path);
            $finalPath = Storage::disk('local')->path($artifact->path);
            if (! rename($path, $finalPath)) throw new \RuntimeException('Unable to finalize export artifact.');
            if (ExportArtifact::query()->whereKey($artifact->id)->where('status', 'running')->whereNull('cancelled_at')->update(['status' => 'ready', 'expires_at' => now()->addDay()]) !== 1) {
                @unlink($finalPath);
                if (is_file($path)) @unlink($path);
                return;
            }
            $notifications->publishEvent([$artifact->user], ['notification_type' => 'export.completed', 'audience' => 'investor', 'severity' => 'info', 'title' => 'Export ready', 'message' => 'Your '.$artifact->dataset.' export is ready to download.', 'primary_action' => ['url' => route('api.exports.download', $artifact->token), 'label' => 'Download export']]);
        } catch (ExportCancelledException) {
            if (is_file($path)) @unlink($path);
            return;
        } catch (\Throwable $error) {
            if (is_file($path)) @unlink($path);
            if (ExportArtifact::query()->whereKey($artifact->id)->where('status', 'running')->whereNull('cancelled_at')->update(['status' => 'failed']) === 1) {
                $notifications->publishEvent([$artifact->user], ['notification_type' => 'export.failed', 'audience' => 'investor', 'severity' => 'action_required', 'title' => 'Export failed', 'message' => 'The '.$artifact->dataset.' export could not be generated. Narrow the scope and try again.']);
            }
            throw $error;
        }
    }
}
