<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserInvite;
use App\Services\UserInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserInviteController extends Controller
{
    public function __construct(
        protected UserInviteService $invites,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->invites->listForAdmin()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $invite = $this->invites->create($request->user(), $validated['email']);

        return response()->json([
            'data' => $this->invites->toAdminPayload($invite),
        ], 201);
    }

    public function regenerate(UserInvite $invite): JsonResponse
    {
        $invite = $this->invites->regenerate($invite);

        return response()->json([
            'data' => $this->invites->toAdminPayload($invite),
        ]);
    }

    public function destroy(UserInvite $invite): JsonResponse
    {
        $this->invites->revoke($invite);

        return response()->json(['message' => 'Invite revoked']);
    }
}
