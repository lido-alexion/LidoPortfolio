<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthAuditService;
use App\Services\PasswordResetLinkService;
use App\Services\SessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PasswordResetAcceptController extends Controller
{
    public function __construct(
        protected PasswordResetLinkService $resetLinks,
        protected AuthAuditService $authAudit,
        protected SessionManagementService $sessions,
    ) {}

    public function show(string $token): JsonResponse
    {
        $link = $this->resetLinks->findByToken($token);

        if ($link === null) {
            return response()->json([
                'valid' => false,
                'message' => 'This password reset link is invalid or has expired. Please contact your administrator for a new link.',
            ], 410);
        }

        if ($link->isUsed()) {
            return response()->json([
                'valid' => false,
                'status' => 'used',
                'message' => 'This password reset link was already used. You can sign in with your new password.',
            ], 409);
        }

        return response()->json([
            'valid' => true,
            'data' => $this->resetLinks->toPublicPayload($link),
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = $this->resetLinks->accept($validated['token'], $validated['password']);
        $remember = $request->boolean('remember');

        // Rotate remember_token before login so a remember cookie (if requested)
        // is issued against the new token, not a pre-reset value.
        $this->sessions->invalidateRememberToken($user);
        $user = $user->fresh();

        Auth::guard('web')->login($user, $remember);
        Auth::shouldUse('web');
        $request->session()->put('logged_in_at', now()->timestamp);
        $request->session()->regenerate();

        // PD-006: keep the newly established session; revoke all pre-existing others.
        // Remember token already rotated above.
        $removed = $this->sessions->destroyOtherSessions(
            $user->id,
            $request->session()->getId()
        );
        if ($removed > 0) {
            $this->authAudit->logLogout($user, $request, 'others');
        }

        $this->authAudit->logLoginSuccess($user, $request);

        return response()->json([
            'message' => 'Password updated. You are now signed in. Other devices have been signed out.',
            'user' => $user,
            'sessions_removed' => $removed,
        ]);
    }
}
