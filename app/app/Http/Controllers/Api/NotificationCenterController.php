<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecipientNotification;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['all', 'needs_attention', 'unread', 'critical', 'resolved'])],
            'severity' => ['nullable', Rule::in(['info', 'action_required', 'critical'])],
            'type' => ['nullable', 'string', 'max:128'],
            'portfolio_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->accountQuery($request)->with('source');
        $view = $validated['view'] ?? 'all';
        if ($view === 'needs_attention') {
            $query->where('condition_state', 'active')
                ->whereHas('source', fn (Builder $source) => $source->whereIn('severity', ['action_required', 'critical']));
        } elseif ($view === 'unread') {
            $query->where('attention_state', 'unread');
        } elseif ($view === 'critical') {
            $query->whereHas('source', fn (Builder $source) => $source->where('severity', 'critical'));
        } elseif ($view === 'resolved') {
            $query->where('condition_state', 'resolved');
        }

        if (isset($validated['severity'])) {
            $query->whereHas('source', fn (Builder $source) => $source->where('severity', $validated['severity']));
        }
        if (isset($validated['type'])) {
            $query->whereHas('source', fn (Builder $source) => $source->where('notification_type', $validated['type']));
        }
        if (isset($validated['portfolio_id'])) {
            $portfolioId = (int) $validated['portfolio_id'];
            $query->whereHas('source', fn (Builder $source) => $source->where('context->portfolio_id', $portfolioId));
        }

        $paginator = $query->orderByDesc('latest_activity_at')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (RecipientNotification $item) => $this->summary($item))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unread_count' => $this->accountQuery($request)->where('attention_state', 'unread')->count(),
                'active_critical_count' => $this->accountQuery($request)
                    ->where('condition_state', 'active')
                    ->whereHas('source', fn (Builder $source) => $source->where('severity', 'critical'))
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, int $notification): JsonResponse
    {
        $item = $this->accountQuery($request)->with(['source.occurrences'])->findOrFail($notification);

        return response()->json(['data' => [
            ...$this->summary($item),
            'timeline' => $item->source->occurrences->sortBy('occurred_at')->values()->map(fn ($occurrence) => [
                'id' => $occurrence->id,
                'activity_type' => $occurrence->activity_type,
                'severity' => $occurrence->severity,
                'snapshot' => $occurrence->snapshot,
                'occurred_at' => $occurrence->occurred_at?->toIso8601String(),
            ])->all(),
        ]]);
    }

    public function markRead(Request $request, int $notification, NotificationPublisher $publisher): JsonResponse
    {
        $item = $this->accountQuery($request)->findOrFail($notification);

        return response()->json(['data' => $this->summary($publisher->markRead($item)->load('source'))]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->accountQuery($request)->where('attention_state', 'unread')->update([
            'attention_state' => 'read',
            'read_at' => now(),
        ]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    private function accountQuery(Request $request): Builder
    {
        return RecipientNotification::query()->where('user_id', $request->user()->id);
    }

    private function summary(RecipientNotification $item): array
    {
        return [
            'id' => $item->id,
            'attention_state' => $item->attention_state,
            'condition_state' => $item->condition_state,
            'read_at' => $item->read_at?->toIso8601String(),
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'latest_activity_at' => $item->latest_activity_at?->toIso8601String(),
            'type' => $item->source->notification_type,
            'severity' => $item->source->severity,
            'title' => $item->source->title,
            'message' => $item->source->message,
            'context' => $item->source->context,
            'primary_action' => $item->source->primary_action,
            'occurrence_count' => $item->source->occurrence_count,
            'first_detected_at' => $item->source->first_detected_at?->toIso8601String(),
            'latest_detected_at' => $item->source->latest_detected_at?->toIso8601String(),
        ];
    }
}
