<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthAuditService;
use App\Services\SessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    public function __construct(
        protected SessionManagementService $sessions,
        protected AuthAuditService $authAudit,
    ) {}

    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email', 'is_admin', 'automated_execution_entitled_at', 'created_at']);

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
                'automated_execution_entitled' => $user->automatedExecutionEntitled(),
                'created_at' => $user->created_at,
            ]),
        ]);
    }

    public function updateAdmin(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'is_admin' => ['You cannot change your own role.'],
            ]);
        }

        $validated = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $user->is_admin = $validated['is_admin'];
        $user->save();

        return response()->json([
            'data' => $user->only(['id', 'name', 'email', 'is_admin', 'created_at']),
        ]);
    }

    public function sessions(Request $request, User $user): JsonResponse
    {
        $this->ensureDifferentUser($request, $user);

        return response()->json([
            'data' => $this->sessions->listForUser($user->id, ''),
        ]);
    }

    public function revokeSession(Request $request, User $user, string $sessionId): JsonResponse
    {
        $this->ensureDifferentUser($request, $user);

        if (! $this->sessions->destroyTargetSession($user->id, $sessionId)) {
            return response()->json([
                'message' => 'The session is no longer active.',
            ], 404);
        }

        $this->authAudit->logAdminForceLogout(
            $request->user(),
            $user,
            $request,
            'single',
            [$sessionId],
            1,
        );

        return response()->json([
            'message' => 'Session revoked',
            'sessions_removed' => 1,
        ]);
    }

    public function revokeAllSessions(Request $request, User $user): JsonResponse
    {
        $this->ensureDifferentUser($request, $user);

        $sessionIds = collect($this->sessions->listForUser($user->id, ''))
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $removed = $this->sessions->destroyAllSessions($user->id);

        $this->authAudit->logAdminForceLogout(
            $request->user(),
            $user,
            $request,
            'all',
            $sessionIds,
            $removed,
        );

        return response()->json([
            'message' => 'All user sessions revoked',
            'sessions_removed' => $removed,
        ]);
    }

    protected function ensureDifferentUser(Request $request, User $user): void
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => ['Use your own session controls to manage your sessions.'],
            ]);
        }
    }
}
