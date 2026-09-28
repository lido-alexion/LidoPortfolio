<?php

namespace App\Services\AccessRequest;

use App\Models\AccessRequest;
use App\Models\AccessRequestAuditEvent;
use App\Models\AccessRequestBan;
use App\Models\User;
use App\Services\UserInviteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessRequestAdminService
{
    public function __construct(
        protected AccessRequestPolicyService $policy,
        protected UserInviteService $invites,
        protected AccessRequestNotificationService $notifications,
        protected AccessRequestAuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        $query = AccessRequest::query()->with(['resolvedBy:id,name,email', 'userInvite:id,email']);

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        } elseif (($filters['pending_only'] ?? true) === true) {
            $query->where('status', AccessRequest::STATUS_PENDING);
        }

        if (! empty($filters['search'])) {
            $search = '%'.strtolower(trim((string) $filters['search'])).'%';
            $query->where(function ($q) use ($search) {
                $q->where('email_normalized', 'like', $search)
                    ->orWhere('full_name', 'like', $search);
            });
        }

        $requests = $query->orderByDesc('id')->limit(200)->get();

        return [
            'pending_count' => AccessRequest::query()->where('status', AccessRequest::STATUS_PENDING)->count(),
            'data' => $requests->map(fn (AccessRequest $r) => $this->toPayload($r))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(AccessRequest $request): array
    {
        $history = AccessRequest::query()
            ->where('email_normalized', $request->email_normalized)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AccessRequest $r) => $this->toPayload($r))
            ->all();

        $ban = $this->policy->activeBan($request->email_normalized);

        return [
            'request' => $this->toPayload($request->load(['resolvedBy:id,name,email', 'userInvite:id,email'])),
            'history' => $history,
            'ban' => $ban ? $this->banPayload($ban) : null,
            'bans' => AccessRequestBan::query()
                ->where('email_normalized', $request->email_normalized)
                ->orderByDesc('id')
                ->get()
                ->map(fn (AccessRequestBan $b) => $this->banPayload($b))
                ->all(),
            'audit_events' => AccessRequestAuditEvent::query()
                ->where('email_normalized', $request->email_normalized)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (AccessRequestAuditEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'access_request_id' => $event->access_request_id,
                    'actor_user_id' => $event->actor_user_id,
                    'context' => $event->context,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function createInvite(User $admin, AccessRequest $request): AccessRequest
    {
        return DB::transaction(function () use ($admin, $request) {
            $locked = AccessRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['This request was already resolved.'],
                ]);
            }

            $check = $this->policy->canIssueInvitationForEmail($locked->email_normalized);
            if (! $check['allowed']) {
                throw ValidationException::withMessages([
                    'request' => ['This request can no longer be approved because the email is no longer eligible.'],
                ]);
            }

            $inviteResult = $this->invites->create($admin, $locked->email_normalized);

            $locked->status = AccessRequest::STATUS_CREATED;
            $locked->resolved_by_user_id = $admin->id;
            $locked->resolved_at = now();
            $locked->user_invite_id = $inviteResult['invite']->id;
            $locked->save();

            $this->audit->record('admin_create', $locked->email_normalized, $locked->id, $admin, [
                'invite_id' => $inviteResult['invite']->id,
            ]);

            $this->notifications->sendInviteEmail($inviteResult['invite'], $inviteResult['raw_token']);

            return $locked->fresh(['resolvedBy:id,name,email', 'userInvite:id,email']);
        });
    }

    public function ignore(User $admin, AccessRequest $request, ?string $reason): AccessRequest
    {
        return DB::transaction(function () use ($admin, $request, $reason) {
            $locked = AccessRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['This request was already resolved.'],
                ]);
            }

            $locked->status = AccessRequest::STATUS_IGNORED;
            $locked->resolved_by_user_id = $admin->id;
            $locked->resolved_at = now();
            $locked->admin_reason = $reason;
            $locked->resubmit_allowed_after = now()->addHours((int) config('access_requests.ignore_cooldown_hours', 72));
            $locked->save();

            $this->audit->record('admin_ignore', $locked->email_normalized, $locked->id, $admin, [
                'has_reason' => $reason !== null && trim($reason) !== '',
            ]);

            $this->notifications->sendIgnoredOutcome($locked);

            return $locked->fresh(['resolvedBy:id,name,email']);
        });
    }

    public function reject(User $admin, AccessRequest $request, ?string $reason): AccessRequest
    {
        return DB::transaction(function () use ($admin, $request, $reason) {
            $locked = AccessRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['This request was already resolved.'],
                ]);
            }

            $locked->status = AccessRequest::STATUS_REJECTED;
            $locked->resolved_by_user_id = $admin->id;
            $locked->resolved_at = now();
            $locked->admin_reason = $reason;
            $locked->save();

            AccessRequestBan::query()->create([
                'email_normalized' => $locked->email_normalized,
                'access_request_id' => $locked->id,
                'rejected_by_user_id' => $admin->id,
                'rejected_at' => now(),
                'internal_reason' => $reason,
            ]);

            $this->audit->record('admin_reject', $locked->email_normalized, $locked->id, $admin, [
                'has_reason' => $reason !== null && trim($reason) !== '',
            ]);

            $this->notifications->sendRejectedOutcome($locked);

            return $locked->fresh(['resolvedBy:id,name,email']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function listBans(): array
    {
        $bans = AccessRequestBan::query()
            ->with(['rejectedBy:id,name,email', 'clearedBy:id,name,email'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return [
            'data' => $bans->map(fn (AccessRequestBan $b) => $this->banPayload($b))->all(),
        ];
    }

    public function clearBan(User $admin, AccessRequestBan $ban): AccessRequestBan
    {
        return DB::transaction(function () use ($admin, $ban) {
            $locked = AccessRequestBan::query()->whereKey($ban->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'ban' => ['This ban was already cleared.'],
                ]);
            }

            $locked->cleared_by_user_id = $admin->id;
            $locked->cleared_at = now();
            $locked->save();

            $this->audit->record('ban_cleared', $locked->email_normalized, $locked->access_request_id, $admin, [
                'ban_id' => $locked->id,
            ]);

            return $locked->fresh(['rejectedBy:id,name,email', 'clearedBy:id,name,email']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function toPayload(AccessRequest $request): array
    {
        return [
            'id' => $request->id,
            'full_name' => $request->full_name,
            'email' => $request->email_normalized,
            'status' => $request->status,
            'verified_at' => $request->verified_at?->toIso8601String(),
            'resolved_at' => $request->resolved_at?->toIso8601String(),
            'resubmit_allowed_after' => $request->resubmit_allowed_after?->toIso8601String(),
            'resolved_by' => $request->resolvedBy?->only(['id', 'name', 'email']),
            'user_invite_id' => $request->user_invite_id,
            'has_admin_reason' => $request->admin_reason !== null && trim((string) $request->admin_reason) !== '',
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function banPayload(AccessRequestBan $ban): array
    {
        return [
            'id' => $ban->id,
            'email' => $ban->email_normalized,
            'active' => $ban->isActive(),
            'access_request_id' => $ban->access_request_id,
            'rejected_at' => $ban->rejected_at?->toIso8601String(),
            'rejected_by' => $ban->rejectedBy?->only(['id', 'name', 'email']),
            'cleared_at' => $ban->cleared_at?->toIso8601String(),
            'cleared_by' => $ban->clearedBy?->only(['id', 'name', 'email']),
            'has_internal_reason' => $ban->internal_reason !== null && trim((string) $ban->internal_reason) !== '',
        ];
    }
}
