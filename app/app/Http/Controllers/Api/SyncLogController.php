<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\SyncRun;
use App\Services\SyncLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncLogController extends Controller
{
    public function __construct(protected SyncLogService $syncLogs) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['nullable', 'in:debug,info,warning,error'],
            'job_name' => ['nullable', 'string', 'max:64'],
            'run_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = SyncLog::query()
            ->orderByDesc('logged_at')
            ->orderByDesc('id');

        $this->syncLogs->applyLogFilters($query, $validated);

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_name' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $query = SyncRun::query()->orderByDesc('started_at');
        if (! empty($validated['job_name'])) {
            $query->where('job_name', $validated['job_name']);
        }

        $runs = $query->limit($limit)->get()
            ->map(fn (SyncRun $run) => $this->syncLogs->formatRun($run))
            ->values();

        return response()->json(['data' => $runs]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'level' => ['nullable', 'in:debug,info,warning,error'],
            'job_name' => ['nullable', 'string', 'max:64'],
            'run_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = SyncLog::query()
            ->orderByDesc('logged_at')
            ->orderByDesc('id');

        $this->syncLogs->applyLogFilters($query, $validated);

        $filename = 'sync-logs-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['logged_at', 'run_id', 'job_name', 'level', 'message', 'context']);

            $query->chunkById(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->logged_at?->toIso8601String(),
                        $log->run_id,
                        $log->job_name,
                        $log->level,
                        $log->message,
                        $log->context ? json_encode($log->context) : '',
                    ]);
                }
            }, 'id');

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
