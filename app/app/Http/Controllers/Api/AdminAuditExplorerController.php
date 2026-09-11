<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExecutionSafetyEvent;
use App\Models\PortfolioProfile;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditExplorerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validatedFilters($request);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $events = collect();

        foreach ($this->safetyEvents($validated)->limit(250)->get() as $event) {
            $events->push($this->formatSafetyEvent($event));
        }
        foreach ($this->systemLogs($validated)->limit(250)->get() as $log) {
            $events->push($this->formatSystemLog($log));
        }

        $sorted = $events->sortByDesc('occurred_at')->values();
        $page = max(1, (int) ($validated['page'] ?? 1));
        $items = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'investors' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
                'portfolios' => PortfolioProfile::query()->orderBy('name')->get(['id', 'user_id', 'name', 'portfolio_type']),
            ],
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $sorted->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $this->validatedFilters($request, exporting: true);
        $filename = 'audit-explorer-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($validated) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['occurred_at', 'source', 'event', 'level', 'status', 'user_id', 'actor_user_id', 'profile_id', 'message', 'context']);

            $rows = collect();
            foreach ($this->safetyEvents($validated)->get() as $event) {
                $rows->push($this->formatSafetyEvent($event, raw: true));
            }
            foreach ($this->systemLogs($validated)->get() as $log) {
                $rows->push($this->formatSystemLog($log, raw: true));
            }

            foreach ($rows->sortByDesc('occurred_at')->values() as $row) {
                fputcsv($handle, [
                    $row['occurred_at'],
                    $row['source'],
                    $row['event'],
                    $row['level'],
                    $row['status'],
                    $row['user_id'],
                    $row['actor_user_id'] ?? '',
                    $row['profile_id'] ?? '',
                    $row['message'],
                    json_encode($row['context'] ?? []),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedFilters(Request $request, bool $exporting = false): array
    {
        return $request->validate([
            'source' => ['nullable', 'string', 'in:safety,system'],
            'level' => ['nullable', 'string', 'in:debug,info,warning,error'],
            'event' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:200'],
            'user_id' => ['nullable', 'integer'],
            'profile_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => [$exporting ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:5000'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function safetyEvents(array $filters): Builder
    {
        $query = ExecutionSafetyEvent::query()->orderByDesc('created_at')->orderByDesc('id');
        if (($filters['source'] ?? null) === 'system') {
            $query->whereRaw('1 = 0');
        }
        if (! empty($filters['event'])) {
            $query->where('event', 'like', '%'.$filters['event'].'%');
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('event', 'like', $search)->orWhere('context', 'like', $search);
            });
        }
        $this->applyDates($query, 'created_at', $filters);

        return $query;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function systemLogs(array $filters): Builder
    {
        $query = SystemLog::query()->orderByDesc('created_at')->orderByDesc('id');
        if (($filters['source'] ?? null) === 'safety') {
            $query->whereRaw('1 = 0');
        }
        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }
        if (! empty($filters['event'])) {
            $query->where('context->event', 'like', '%'.$filters['event'].'%');
        }
        if (! empty($filters['user_id'])) {
            $query->where('context->user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['profile_id'])) {
            $query->where('context->profile_id', (int) $filters['profile_id']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('message', 'like', $search)
                    ->orWhere('category', 'like', $search)
                    ->orWhere('context', 'like', $search);
            });
        }
        $this->applyDates($query, 'created_at', $filters);

        return $query;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function applyDates(Builder $query, string $column, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where($column, '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function formatSafetyEvent(ExecutionSafetyEvent $event, bool $raw = false): array
    {
        return [
            'id' => 'safety-'.$event->id,
            'source' => 'safety',
            'occurred_at' => $event->created_at?->toIso8601String(),
            'event' => $event->event,
            'level' => $event->status === 'completed' ? 'warning' : 'error',
            'status' => $event->status,
            'user_id' => $event->user_id,
            'actor_user_id' => $event->actor_user_id,
            'profile_id' => null,
            'message' => 'Execution safety event',
            'context' => $raw ? $event->context : $this->curatedContext($event->context ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function formatSystemLog(SystemLog $log, bool $raw = false): array
    {
        $context = is_array($log->context) ? $log->context : [];

        return [
            'id' => 'system-'.$log->id,
            'source' => 'system',
            'occurred_at' => $log->created_at?->toIso8601String(),
            'event' => (string) ($context['event'] ?? $log->category),
            'level' => $log->level,
            'status' => $log->level,
            'user_id' => $context['user_id'] ?? null,
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'profile_id' => $context['profile_id'] ?? null,
            'message' => $log->message,
            'context' => $raw ? $context : $this->curatedContext($context),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function curatedContext(array $context): array
    {
        return collect($context)->only([
            'event', 'engine', 'profile_id', 'user_id', 'actor_user_id', 'reason',
            'status', 'order_id', 'recommendation_id', 'broker_order_id',
            'eligible_orders', 'cancelled_orders', 'request_id',
        ])->all();
    }
}
