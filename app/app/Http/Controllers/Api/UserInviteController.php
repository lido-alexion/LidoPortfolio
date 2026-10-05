<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserInvite;
use App\Services\UserInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $result = DB::transaction(function () use ($request, $validated) {
            $created = $this->invites->create($request->user(), $validated['email']);
            $invite = $created['invite'];
            DB::afterCommit(fn () => $this->invites->retryInvitationEmail($invite));

            return $created;
        });

        return response()->json([
            'data' => $this->invites->toAdminPayload($result['invite'], $result['raw_token']),
            'message' => 'Invitation created. Copy and save the invitation URL now — regenerating later will invalidate it.',
        ], 201);
    }

    public function regenerate(UserInvite $invite): JsonResponse
    {
        $result = DB::transaction(function () use ($invite) {
            $result = $this->invites->regenerate($invite);
            $updated = $result['invite'];
            DB::afterCommit(fn () => $this->invites->retryInvitationEmail($updated));

            return $result;
        });

        return response()->json([
            'data' => $this->invites->toAdminPayload($result['invite'], $result['raw_token']),
            'message' => 'Invitation URL regenerated. The previous URL no longer works. Copy and save the new URL.',
        ]);
    }

    public function copy(UserInvite $invite): JsonResponse
    {
        return response()->json(['data' => $this->invites->copyableInvitation($invite)]);
    }

    public function retryEmail(UserInvite $invite): JsonResponse
    {
        if ($invite->email_delivery_status !== 'failed') {
            return response()->json(['message' => 'Only failed invitation email deliveries can be retried.'], 422);
        }
        $this->invites->retryInvitationEmail($invite);

        return response()->json(['message' => 'Invitation email queued for retry.']);
    }

    public function destroy(UserInvite $invite): JsonResponse
    {
        $this->invites->revoke($invite);

        return response()->json(['message' => 'Invite revoked']);
    }
}
