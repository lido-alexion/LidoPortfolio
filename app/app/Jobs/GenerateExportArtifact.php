<?php

namespace App\Jobs;

use App\Models\ExportArtifact;
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

    public function handle(ExportFileWriter $writer, NotificationPublisher $notifications): void
    {
        $artifact = ExportArtifact::find($this->artifactId);
        if (! $artifact || $artifact->cancelled_at) return;
        $path = Storage::disk('local')->path($artifact->path);
        Storage::disk('local')->makeDirectory(dirname($artifact->path));
        try {
            $artifact->update(['status' => 'running']);
            if ($artifact->format === 'csv') $writer->csv($this->definition['columns'], $this->definition['rows'], $path);
            else $writer->xlsx([['name' => 'Data', 'columns' => $this->definition['columns'], 'rows' => $this->definition['rows']], ['name' => 'Metadata', 'columns' => ['field', 'value'], 'rows' => collect($this->definition['metadata'] ?? [])->map(fn ($value, $field) => ['field' => $field, 'value' => is_scalar($value) ? $value : json_encode($value)])->values()->all()]], $path);
            $artifact->update(['status' => 'ready', 'expires_at' => now()->addDay()]);
            $notifications->publishEvent([$artifact->user], ['notification_type' => 'export.completed', 'audience' => 'investor', 'severity' => 'info', 'title' => 'Export ready', 'message' => 'Your '.$artifact->dataset.' export is ready to download.', 'primary_action' => ['url' => route('api.exports.download', $artifact->token), 'label' => 'Download export']]);
        } catch (\Throwable $error) {
            $artifact->update(['status' => 'failed']);
            if (Storage::disk('local')->exists($artifact->path)) Storage::disk('local')->delete($artifact->path);
            $notifications->publishEvent([$artifact->user], ['notification_type' => 'export.failed', 'audience' => 'investor', 'severity' => 'action_required', 'title' => 'Export failed', 'message' => 'The '.$artifact->dataset.' export could not be generated. Narrow the scope and try again.']);
            throw $error;
        }
    }
}
