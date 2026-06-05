<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthAuditService;
use App\Services\SessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthAuditService $authAudit,
        protected SessionManagementService $sessions,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:portfolio_users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $remember = $request->boolean('remember');
        Auth::login($user, $remember);
        $request->session()->put('logged_in_at', now()->timestamp);
        $request->session()->regenerate();

        $this->authAudit->logLoginSuccess($user, $request);

        return response()->json([
            'user' => $user,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ], $remember)) {
            $this->authAudit->logLoginFailure($request, $validated['email']);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $request->session()->put('logged_in_at', now()->timestamp);
        $request->session()->regenerate();

        $this->authAudit->logLoginSuccess($user, $request);

        return response()->json([
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            $this->authAudit->logLogout($user, $request, 'current');
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
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
