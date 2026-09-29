<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateExportArtifact;
use App\Models\ExportArtifact;
use App\Models\ExportBasket;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\Export\ExportFileWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function __construct(private ExportDatasetRegistry $datasets, private ExportFileWriter $writer) {}

    public function catalog(): JsonResponse { return response()->json(['data' => $this->datasets->catalog()]); }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['dataset' => ['required', 'string'], 'format' => ['required', 'in:csv,xlsx'], 'scope' => ['required', 'in:current,full,selected'], 'fields' => ['sometimes', 'array'], 'selected' => ['sometimes', 'array']]);
        $resolved = $this->datasets->resolve($data['dataset'], \activePortfolio());
        $columns = array_values(array_intersect($data['fields'] ?? $resolved['columns'], $resolved['columns']));
        if ($columns === []) return response()->json(['message' => 'Select at least one export field.'], 422);
        if (count($resolved['rows']) > 50000) return response()->json(['message' => 'This export is too large. Narrow the scope before exporting.'], 422);
        $token = (string) Str::uuid(); $relative = 'exports/'.$request->user()->id.'/'.$token.'.'.$data['format']; Storage::disk('local')->makeDirectory(dirname($relative));
        $path = Storage::disk('local')->path($relative);
        $isBackground = count($resolved['rows']) > 1000;
        $artifact = ExportArtifact::create(['user_id' => $request->user()->id, 'token' => $token, 'dataset' => $data['dataset'], 'format' => $data['format'], 'path' => $relative, 'status' => $isBackground ? 'queued' : 'ready', 'expires_at' => $isBackground ? null : now()->addDay(), 'metadata' => ['scope' => $data['scope'], 'fields' => $columns, ...($resolved['metadata'] ?? [])]]);
        $definition = ['columns' => $columns, 'rows' => $resolved['rows']];
        if ($isBackground) dispatch(new GenerateExportArtifact($artifact->id, $definition));
        else if ($data['format'] === 'csv') $this->writer->csv($columns, $resolved['rows'], $path); else $this->writer->xlsx([['name' => 'Data', 'columns' => $columns, 'rows' => $resolved['rows']]], $path);
        return response()->json(['data' => ['id' => $artifact->id, 'token' => $artifact->token, 'status' => $artifact->status, 'download_url' => route('api.exports.download', $artifact->token), 'expires_at' => $artifact->expires_at]], $isBackground ? 202 : 200);
    }

    public function status(Request $request, string $token): JsonResponse
    {
        $artifact = ExportArtifact::query()->where('user_id', $request->user()->id)->where('token', $token)->firstOrFail();
        return response()->json(['data' => ['token' => $artifact->token, 'status' => $artifact->status, 'expires_at' => $artifact->expires_at, 'download_url' => $artifact->status === 'ready' ? route('api.exports.download', $artifact->token) : null]]);
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        $artifact = ExportArtifact::query()->where('user_id', $request->user()->id)->where('token', $token)->firstOrFail();
        if (in_array($artifact->status, ['queued', 'running'], true)) $artifact->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        if ($artifact->path && Storage::disk('local')->exists($artifact->path)) Storage::disk('local')->delete($artifact->path);
        return response()->json(['data' => ['status' => $artifact->fresh()->status]]);
    }

    public function download(Request $request, string $token)
    {
        $artifact = ExportArtifact::query()->where('user_id', $request->user()->id)->where('token', $token)->firstOrFail();
        abort_if($artifact->status !== 'ready' || $artifact->expires_at?->isPast() || ! Storage::disk('local')->exists($artifact->path), 410);
        return Storage::disk('local')->download($artifact->path, 'stox-'.$artifact->dataset.'.'.$artifact->format);
    }

    public function basket(Request $request): JsonResponse
    {
        $basket = ExportBasket::firstOrCreate(['user_id' => $request->user()->id], ['items' => []]);
        return response()->json(['data' => $basket]);
    }

    public function updateBasket(Request $request): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:10']]);
        foreach ($data['items'] as $item) if (! is_array($item) || empty($item['dataset'])) return response()->json(['message' => 'Each basket item needs a dataset.'], 422);
        $basket = ExportBasket::updateOrCreate(['user_id' => $request->user()->id], ['items' => $data['items']]);
        return response()->json(['data' => $basket]);
    }
}
