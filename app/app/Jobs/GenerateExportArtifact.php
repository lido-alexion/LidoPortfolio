<?php

namespace App\Jobs;

use App\Models\ExportArtifact;
use App\Services\Export\ExportFileWriter;
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

    public function handle(ExportFileWriter $writer): void
    {
        $artifact = ExportArtifact::find($this->artifactId);
        if (! $artifact || $artifact->cancelled_at) return;
        $path = Storage::disk('local')->path($artifact->path);
        Storage::disk('local')->makeDirectory(dirname($artifact->path));
        if ($artifact->format === 'csv') $writer->csv($this->definition['columns'], $this->definition['rows'], $path);
        else $writer->xlsx([['name' => 'Data', ...$this->definition]], $path);
        $artifact->update(['status' => 'ready', 'expires_at' => now()->addDay()]);
    }
}
