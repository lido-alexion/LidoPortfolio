<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Models\RecipientNotification;
use App\Services\Notification\NotificationPublisher;
use App\Services\Notification\NotificationDeliveryPlanner;
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
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:128'],
            'channel' => ['nullable', Rule::in(['email', 'telegram', 'webhook'])],
            'delivery_status' => ['nullable', Rule::in(['queued', 'processing', 'delivered', 'failed', 'suppressed'])],
            'read_state' => ['nullable', Rule::in(['read', 'unread'])],
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
        if (isset($validated['q'])) {
            $keyword = '%'.addcslashes(trim($validated['q']), '%_\\\\').'%';
            $query->whereHas('source', fn (Builder $source) => $source->where('title', 'like', $keyword)->orWhere('message', 'like', $keyword));
        }
        if (isset($validated['category'])) {
            $query->whereHas('source', fn (Builder $source) => $source->where('notification_type', $validated['category']));
        }
        if (isset($validated['from'])) $query->where('latest_activity_at', '>=', $validated['from']);
        if (isset($validated['to'])) $query->where('latest_activity_at', '<=', $validated['to'].' 23:59:59');
        if (isset($validated['read_state'])) $query->where('attention_state', $validated['read_state']);
        if (isset($validated['channel']) || isset($validated['delivery_status'])) {
            $query->whereHas('deliveries', function (Builder $delivery) use ($validated): void {
                if (isset($validated['channel'])) $delivery->where('channel', $validated['channel']);
                if (isset($validated['delivery_status'])) $delivery->where('status', $validated['delivery_status']);
            });
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
        $item = $this->accountQuery($request)->with(['source.occurrences', 'deliveries.attempts'])->findOrFail($notification);

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

    public function retryDelivery(
        Request $request,
        int $notification,
        int $delivery,
        NotificationDeliveryPlanner $planner,
    ): JsonResponse {
        $item = $this->accountQuery($request)->with('source')->findOrFail($notification);
        $deliveryModel = $item->deliveries()->whereKey($delivery)->firstOrFail();

        if ($item->condition_state !== 'active') {
            return response()->json(['message' => 'This notification no longer requires delivery.'], 422);
        }
        if ($deliveryModel->status !== 'failed') {
            return response()->json(['message' => 'Only failed deliveries can be retried.'], 422);
        }

        $planner->requeueFailedDelivery($deliveryModel);

        return response()->json(['data' => $this->summary($item->fresh(['source', 'deliveries.attempts']))]);
    }

    public function markRead(Request $request, int $notification, NotificationPublisher $publisher): JsonResponse
    {
        $item = $this->accountQuery($request)->findOrFail($notification);

        return response()->json(['data' => $this->summary($publisher->markRead($item)->load('source'))]);
    }

    public function markUnread(Request $request, int $notification): JsonResponse
    {
        $item = $this->accountQuery($request)->findOrFail($notification);
        $item->update(['attention_state' => 'unread', 'read_at' => null]);

        return response()->json(['data' => $this->summary($item->fresh('source'))]);
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
            'deliveries' => $item->relationLoaded('deliveries')
                ? $item->deliveries->map(fn (NotificationDelivery $delivery) => [
                    'id' => $delivery->id,
                    'channel' => $delivery->channel,
                    'delivery_kind' => $delivery->delivery_kind,
                    'status' => $delivery->status,
                    'attempt_count' => $delivery->attempts->count(),
                    'last_error_code' => $delivery->last_error_code,
                    'last_response_status' => $delivery->attempts->sortByDesc('attempt_number')->first()?->response_status,
                    'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                    'retryable' => $delivery->status === 'failed' && $item->condition_state === 'active',
                ])->values()->all()
                : [],
        ];
    }
}
