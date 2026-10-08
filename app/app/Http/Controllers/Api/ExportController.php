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

    public function catalog(): JsonResponse
    {
        $profile = \activePortfolio();
        $data = array_map(function (array $dataset) use ($profile) {
            $this->datasets->assertAuthorized($dataset['id'], $profile, (int) auth()->id());
            $dataset['estimate'] = $this->datasets->estimate($dataset['id'], $profile);
            return $dataset;
        }, $this->datasets->catalog());
        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dataset' => ['required', 'string'], 'format' => ['required', 'in:csv,xlsx'], 'scope' => ['required', 'in:current,full,selected'],
            'fields' => ['sometimes', 'array', 'max:'.config('exports.max_fields')], 'fields.*' => ['string'],
            'selected' => ['sometimes', 'array', 'max:'.config('exports.max_rows')], 'selected.*' => ['string', 'max:120'],
            'filters' => ['sometimes', 'array'], 'filters.range' => ['required_if:scope,current', 'in:90d,180d,365d,all'],
            'filters.sort_by' => ['required_if:scope,current', 'in:snapshot_date'], 'filters.sort_direction' => ['required_if:scope,current', 'in:asc,desc'],
        ]);
        if (! $this->datasets->supportsScope($data['dataset'], $data['scope'])) return response()->json(['message' => 'This dataset does not support the selected scope. Choose a supported scope.'], 422);
        $profile = \activePortfolio();
        $this->datasets->assertAuthorized($data['dataset'], $profile, (int) $request->user()->id);
        if ($data['scope'] === 'current' && ! in_array($data['dataset'], ['portfolio-growth', 'portfolio-snapshots'], true)) return response()->json(['message' => 'Current scope requires supported snapshot filters.'], 422);
        $filters = $data['scope'] === 'current' ? ['range' => $data['filters']['range'], 'sort_by' => $data['filters']['sort_by'], 'sort_direction' => $data['filters']['sort_direction']] : [];
        $catalogDefinition = collect($this->datasets->catalog())->firstWhere('id', $data['dataset']);
        if (! $catalogDefinition) return response()->json(['message' => 'This dataset is not available for export.'], 404);
        $preflightColumns = $data['fields'] ?? $catalogDefinition['fields'];
        if ($preflightColumns === [] || array_diff($preflightColumns, $catalogDefinition['fields'])) return response()->json(['message' => 'One or more selected fields are unavailable for export.'], 422);
        $estimatedRows = $data['scope'] === 'selected'
            ? count($data['selected'] ?? [])
            : $this->datasets->estimate($data['dataset'], $profile, $filters)['rows'];
        if ($estimatedRows > config('exports.max_rows')) return response()->json(['message' => 'This export exceeds a safety limit. Narrow the scope or select fewer fields.'], 422);
        if ($estimatedRows > config('exports.sync_rows')) {
            $token = (string) Str::uuid();
            $relative = 'exports/'.$request->user()->id.'/'.$token.'.'.$data['format'];
            Storage::disk('local')->makeDirectory(dirname($relative));
            $metadata = [
                'dataset' => $data['dataset'],
                'scope' => $data['scope'],
                'fields' => array_values($preflightColumns),
                'field_labels' => array_map(fn ($field) => $field['label'], array_intersect_key($catalogDefinition['field_metadata'], array_flip($preflightColumns))),
                ...($filters ? ['filters' => ['range' => $filters['range']], 'sort' => ['by' => $filters['sort_by'], 'direction' => $filters['sort_direction']]] : []),
            ];
            $artifact = ExportArtifact::create([
                'user_id' => $request->user()->id,
                'token' => $token,
                'dataset' => $data['dataset'],
                'format' => $data['format'],
                'path' => $relative,
                'status' => 'queued',
                'expires_at' => null,
                'metadata' => $metadata,
            ]);
            dispatch(new GenerateExportArtifact($artifact->id, [
                'dataset' => $data['dataset'],
                'profile_id' => $profile->id,
                'scope' => $data['scope'],
                'selected' => $data['selected'] ?? [],
                'filters' => $filters,
                'columns' => array_values($preflightColumns),
                'metadata' => $metadata,
            ]));
            return response()->json(['data' => ['id' => $artifact->id, 'token' => $artifact->token, 'status' => $artifact->status, 'download_url' => route('api.exports.download', $artifact->token), 'expires_at' => null]], 202);
        }
        $resolved = $this->datasets->resolve($data['dataset'], $profile, $filters);
        if ($data['scope'] === 'selected') {
            $selected = $data['selected'] ?? [];
            if ($selected === [] || ! isset($resolved['identities'])) return response()->json(['message' => 'This dataset does not expose stable selected-row identities.'], 422);
            if (array_diff($selected, $resolved['identities'])) return response()->json(['message' => 'One or more selected rows are stale or unavailable. Refresh the selection and try again.'], 422);
            $identityRows = array_combine($resolved['identities'], $resolved['rows']);
            $resolved['rows'] = array_map(fn ($identity) => $identityRows[$identity], $selected);
            if ($resolved['rows'] === []) return response()->json(['message' => 'None of the selected rows are available for export.'], 422);
        }
        $requestedFields = $data['fields'] ?? $resolved['columns'];
        if (array_diff($requestedFields, $resolved['columns'])) return response()->json(['message' => 'One or more selected fields are unavailable for export.'], 422);
        $columns = array_values(array_intersect($requestedFields, $resolved['columns']));
        if ($columns === []) return response()->json(['message' => 'Select at least one export field.'], 422);
        if (count($columns) > config('exports.max_fields') || count($resolved['rows']) > config('exports.max_rows')) return response()->json(['message' => 'This export exceeds a safety limit. Narrow the scope or select fewer fields.'], 422);
        $token = (string) Str::uuid(); $relative = 'exports/'.$request->user()->id.'/'.$token.'.'.$data['format']; Storage::disk('local')->makeDirectory(dirname($relative));
        $path = Storage::disk('local')->path($relative);
        $isBackground = count($resolved['rows']) > config('exports.sync_rows');
        $artifact = ExportArtifact::create(['user_id' => $request->user()->id, 'token' => $token, 'dataset' => $data['dataset'], 'format' => $data['format'], 'path' => $relative, 'status' => $isBackground ? 'queued' : 'ready', 'expires_at' => $isBackground ? null : now()->addDay(), 'metadata' => ['scope' => $data['scope'], 'fields' => $columns, ...($resolved['metadata'] ?? [])]]);
        $definition = ['columns' => $columns, 'rows' => $resolved['rows']];
        $definition['metadata'] = ['dataset' => $data['dataset'], 'scope' => $data['scope'], 'exported_at' => now()->toIso8601String(), ...($resolved['metadata'] ?? [])];
        if ($isBackground) dispatch(new GenerateExportArtifact($artifact->id, [
            'dataset' => $data['dataset'], 'profile_id' => $profile->id, 'scope' => $data['scope'],
            'selected' => $data['selected'] ?? [], 'filters' => $filters, 'columns' => $columns, 'metadata' => $definition['metadata'],
        ]));
        else {
            try {
                if ($data['format'] === 'csv') $this->writer->csv($columns, $resolved['rows'], $path, $definition['metadata']);
                else $this->writer->xlsx([['name' => 'Data', 'columns' => $columns, 'rows' => $resolved['rows'], 'metadata' => $definition['metadata']]], $path);
            } catch (\Throwable $error) {
                $artifact->update(['status' => 'failed']);
                if (Storage::disk('local')->exists($relative)) Storage::disk('local')->delete($relative);
                if ($error instanceof \RuntimeException && str_contains($error->getMessage(), 'exceeds')) return response()->json(['message' => $error->getMessage()], 422);
                throw $error;
            }
        }
        return response()->json(['data' => ['id' => $artifact->id, 'token' => $artifact->token, 'status' => $artifact->status, 'download_url' => route('api.exports.download', $artifact->token), 'expires_at' => $artifact->expires_at]], $isBackground ? 202 : 200);
    }

    public function status(Request $request, string $token): JsonResponse
    {
        $artifact = ExportArtifact::query()->where('user_id', $request->user()->id)->where('token', $token)->firstOrFail();
        return response()->json(['data' => ['token' => $artifact->token, 'status' => $artifact->status, 'expires_at' => $artifact->expires_at, 'download_url' => ($artifact->status === 'ready' && $artifact->expires_at?->isFuture()) ? route('api.exports.download', $artifact->token) : null]]);
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        $artifact = ExportArtifact::query()->where('user_id', $request->user()->id)->where('token', $token)->firstOrFail();
        $cancelled = ExportArtifact::query()->whereKey($artifact->id)->whereIn('status', ['queued', 'running'])->whereNull('cancelled_at')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'expires_at' => now()->addDay()]) === 1;
        if ($cancelled && $artifact->path) {
            foreach ([$artifact->path, $artifact->path.'.partial'] as $path) if (Storage::disk('local')->exists($path)) Storage::disk('local')->delete($path);
        }
        return response()->json(['data' => ['status' => $artifact->fresh()->status]]);
    }

    public function download(Request $request, string $token)
    {
        $artifact = ExportArtifact::query()->where('user_id', $request->user()->id)->where('token', $token)->firstOrFail();
        abort_unless($artifact->path === 'exports/'.$artifact->user_id.'/'.$artifact->token.'.'.$artifact->format, 404);
        $expiresAt = $artifact->expires_at;
        abort_if($artifact->status !== 'ready' || ! $expiresAt || ! $expiresAt->isFuture() || ! Storage::disk('local')->exists($artifact->path), 410);
        return Storage::disk('local')->download($artifact->path, 'stox-'.(\Illuminate\Support\Str::slug($artifact->dataset) ?: 'data').'.'.$artifact->format);
    }

    public function basket(Request $request): JsonResponse
    {
        $basket = ExportBasket::firstOrCreate(['user_id' => $request->user()->id], ['items' => []]);
        return response()->json(['data' => $basket]);
    }

    public function updateBasket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:'.config('exports.max_basket_items')],
            'items.*' => ['required', 'array'], 'items.*.dataset' => ['required', 'string', 'max:120'],
            'items.*.sheet_name' => ['nullable', 'string', 'max:255'],
            'items.*.fields' => ['sometimes', 'array', 'max:'.config('exports.max_fields')], 'items.*.fields.*' => ['string'],
            'items.*.scope' => ['sometimes', 'in:current,full,selected'],
            'items.*.selected' => ['sometimes', 'array', 'max:'.config('exports.max_rows')], 'items.*.selected.*' => ['string', 'max:120'],
            'items.*.filters' => ['sometimes', 'array'], 'items.*.filters.range' => ['required_if:items.*.scope,current', 'in:90d,180d,365d,all'],
            'items.*.filters.sort_by' => ['required_if:items.*.scope,current', 'in:snapshot_date'], 'items.*.filters.sort_direction' => ['required_if:items.*.scope,current', 'in:asc,desc'],
        ]);
        foreach ($data['items'] as $item) if (! is_array($item) || empty($item['dataset'])) return response()->json(['message' => 'Each basket item needs a dataset.'], 422);
        foreach ($data['items'] as $item) {
            if (($item['scope'] ?? 'full') === 'current' && (! in_array($item['dataset'], ['portfolio-growth', 'portfolio-snapshots'], true) || ! in_array($item['filters']['range'] ?? null, ['90d', '180d', '365d', 'all'], true) || ($item['filters']['sort_by'] ?? null) !== 'snapshot_date' || ! in_array($item['filters']['sort_direction'] ?? null, ['asc', 'desc'], true))) return response()->json(['message' => 'A current-scope basket item needs valid snapshot filters and sort.'], 422);
        }
        $basket = ExportBasket::updateOrCreate(['user_id' => $request->user()->id], ['items' => $data['items']]);
        return response()->json(['data' => $basket]);
    }

    public function exportBasket(Request $request): JsonResponse
    {
        $basket = ExportBasket::query()->where('user_id', $request->user()->id)->first();
        if (! $basket || count($basket->items ?? []) === 0) return response()->json(['message' => 'Add at least one dataset to the export basket.'], 422);
        if (count($basket->items) > config('exports.max_sheets')) return response()->json(['message' => 'The export basket exceeds the workbook sheet limit. Remove some items and try again.'], 422);
        $sheets = [];
        $totalRows = 0;
        $totalFields = 0;
        foreach ($basket->items as $index => $item) {
            $scope = $item['scope'] ?? 'full';
            if ($scope === 'current' && (! in_array($item['dataset'] ?? '', ['portfolio-growth', 'portfolio-snapshots'], true) || ! in_array($item['filters']['range'] ?? null, ['90d', '180d', '365d', 'all'], true) || ($item['filters']['sort_by'] ?? null) !== 'snapshot_date' || ! in_array($item['filters']['sort_direction'] ?? null, ['asc', 'desc'], true))) return response()->json(['message' => 'A current-scope basket item needs valid snapshot filters and sort.'], 422);
            $filters = $scope === 'current' ? ['range' => $item['filters']['range'], 'sort_by' => $item['filters']['sort_by'], 'sort_direction' => $item['filters']['sort_direction']] : [];
            try { $profile = \activePortfolio(); $this->datasets->assertAuthorized((string) ($item['dataset'] ?? ''), $profile, (int) $request->user()->id); $resolved = $this->datasets->resolve((string) ($item['dataset'] ?? ''), $profile, $filters); }
            catch (\Throwable) { return response()->json(['message' => 'The export basket contains an unavailable dataset. Refresh it and try again.'], 422); }
            if (! $this->datasets->supportsScope($item['dataset'], $scope)) return response()->json(['message' => 'A basket item has an unsupported scope. Edit the item and try again.'], 422);
            $requestedFields = $item['fields'] ?? $resolved['columns'];
            if (! is_array($requestedFields) || array_diff($requestedFields, $resolved['columns'])) return response()->json(['message' => 'The export basket contains unavailable fields. Review the item and try again.'], 422);
            $columns = array_values(array_intersect($requestedFields, $resolved['columns']));
            if ($columns === []) return response()->json(['message' => 'The export basket contains an item with no valid fields.'], 422);
            if ($scope === 'selected') {
                $selected = collect($item['selected'] ?? []);
                if ($selected->isEmpty() || ! isset($resolved['identities'])) return response()->json(['message' => 'A selected-scope basket item has no valid stable row identities. Edit the item and try again.'], 422);
                if ($selected->diff($resolved['identities'])->isNotEmpty()) return response()->json(['message' => 'A selected-scope basket item is stale. Edit or remove it and try again.'], 422);
                $identityRows = array_combine($resolved['identities'], $resolved['rows']);
                $resolved['rows'] = $selected->map(fn ($identity) => $identityRows[$identity])->all();
                if ($resolved['rows'] === []) return response()->json(['message' => 'A selected-scope basket item is stale. Edit or remove it and try again.'], 422);
            }
            $totalRows += count($resolved['rows']);
            $totalFields += count($columns);
            if ($totalRows > config('exports.max_rows')) return response()->json(['message' => 'The export basket exceeds the maximum row count. Remove items or narrow the scope.'], 422);
            if ($totalFields > config('exports.max_fields')) return response()->json(['message' => 'The export basket exceeds the maximum field count. Remove items or select fewer fields.'], 422);
            $name = preg_replace('/[\\\/\?\*\[\]:]/', '-', (string) ($item['sheet_name'] ?? $item['dataset'] ?? 'Dataset '.($index + 1)));
            $metadata = ['dataset' => $item['dataset'], 'scope' => $scope, 'fields' => $columns, 'exported_at' => now()->toIso8601String(), ...($resolved['metadata'] ?? [])];
            $sheets[] = ['name' => $name ?: 'Dataset '.($index + 1), 'columns' => $columns, 'rows' => $resolved['rows'], 'metadata' => $metadata];
        }
        $token = (string) Str::uuid(); $relative = 'exports/'.$request->user()->id.'/'.$token.'.xlsx'; Storage::disk('local')->makeDirectory(dirname($relative));
        try { $this->writer->xlsx($sheets, Storage::disk('local')->path($relative)); }
        catch (\Throwable $error) {
            if (Storage::disk('local')->exists($relative)) Storage::disk('local')->delete($relative);
            if ($error instanceof \RuntimeException && str_contains($error->getMessage(), 'exceeds')) return response()->json(['message' => $error->getMessage()], 422);
            throw $error;
        }
        $artifact = ExportArtifact::create(['user_id' => $request->user()->id, 'token' => $token, 'dataset' => 'basket', 'format' => 'xlsx', 'path' => $relative, 'status' => 'ready', 'expires_at' => now()->addDay(), 'metadata' => ['item_count' => count($sheets), 'datasets' => array_column($basket->items, 'dataset')]]);
        return response()->json(['data' => ['token' => $artifact->token, 'status' => 'ready', 'download_url' => route('api.exports.download', $artifact->token), 'expires_at' => $artifact->expires_at]]);
    }
}
