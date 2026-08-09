<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthAuditService;
use App\Services\PortfolioProfileService;
use App\Services\SessionManagementService;
use App\Services\UserInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthAuditService $authAudit,
        protected SessionManagementService $sessions,
        protected UserInviteService $invites,
        protected PortfolioProfileService $portfolios,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $pendingInvite = $this->invites->pendingForEmail($validated['email']);
        if ($pendingInvite !== null) {
            return response()->json([
                'message' => 'An invitation is pending for this email. Please use the invitation link provided by your administrator.',
                'invite_setup_required' => true,
            ], 422);
        }

        $remember = $request->boolean('remember');

        // Always use the session (web) guard — after auth:sanctum routes the default
        // driver may be the Sanctum RequestGuard, which does not implement attempt().
        if (! Auth::guard('web')->attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ], $remember)) {
            $this->authAudit->logLoginFailure($request, $validated['email']);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        Auth::shouldUse('web');

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $request->session()->put('logged_in_at', now()->timestamp);
        $request->session()->regenerate();

        $this->authAudit->logLoginSuccess($user, $request);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user() ?? $request->user();
        if ($user) {
            $this->authAudit->logLogout($user, $request, 'current');
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Clear Sanctum RequestGuard cache left by auth:sanctum (needed for tests/Octane).
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user ? $this->userPayload($user) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        $payload = $user->toArray();
        $payload['default_portfolio_id'] = $this->portfolios->defaultForUser($user)?->id;

        return $payload;
    }

    public function csrfToken(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = $this->sessions->listForUser($user->id, $request->session()->getId());

        return response()->json(['data' => $list]);
    }

    public function logoutOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $deleted = $this->sessions->destroyOtherSessions($user->id, $request->session()->getId());
        $this->authAudit->logLogout($user, $request, 'others');

        return response()->json([
            'message' => 'Other sessions logged out',
            'sessions_removed' => $deleted,
        ]);
    }

    public function logoutSession(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();

        if ($sessionId === $request->session()->getId()) {
            return $this->logout($request);
        }

        if (! $this->sessions->destroySession($user->id, $sessionId, $request->session()->getId())) {
            $this->authAudit->logSuspicious($request, 'Attempt to revoke foreign session');
            throw ValidationException::withMessages([
                'session' => ['Session not found.'],
            ]);
        }

        $this->authAudit->logLogout($user, $request, 'remote');

        return response()->json(['message' => 'Session revoked']);
    }
}
