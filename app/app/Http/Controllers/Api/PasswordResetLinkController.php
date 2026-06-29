<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetLink;
use App\Models\User;
use App\Services\PasswordResetLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetLinkController extends Controller
{
    public function __construct(
        protected PasswordResetLinkService $resetLinks,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->resetLinks->listForAdmin()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:portfolio_users,id'],
        ]);

        $targetUser = User::query()->findOrFail($validated['user_id']);
        $link = $this->resetLinks->create($request->user(), $targetUser);

        return response()->json([
            'data' => $this->resetLinks->toAdminPayload($link->load(['user:id,name,email', 'createdBy:id,name,email'])),
        ], 201);
    }

    public function regenerate(PasswordResetLink $passwordResetLink): JsonResponse
    {
        $link = $this->resetLinks->regenerate($passwordResetLink);

        return response()->json([
            'data' => $this->resetLinks->toAdminPayload($link),
        ]);
    }

    public function destroy(PasswordResetLink $passwordResetLink): JsonResponse
    {
        $this->resetLinks->revoke($passwordResetLink);

        return response()->json(['message' => 'Password reset link revoked']);
    }
}
