<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthAuditService;
use App\Services\PasswordResetLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PasswordResetAcceptController extends Controller
{
    public function __construct(
        protected PasswordResetLinkService $resetLinks,
        protected AuthAuditService $authAudit,
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

        Auth::guard('web')->login($user, $remember);
        $request->session()->put('logged_in_at', now()->timestamp);
        $request->session()->regenerate();

        $this->authAudit->logLoginSuccess($user, $request);

        return response()->json([
            'message' => 'Password updated. You are now signed in.',
            'user' => $user,
        ]);
    }
}
