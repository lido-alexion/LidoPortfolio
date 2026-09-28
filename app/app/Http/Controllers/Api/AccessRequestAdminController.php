<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\AccessRequestBan;
use App\Services\AccessRequest\AccessRequestAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessRequestAdminController extends Controller
{
    public function __construct(
        protected AccessRequestAdminService $admin,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:255'],
            'pending_only' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->admin->list($validated));
    }

    public function show(AccessRequest $accessRequest): JsonResponse
    {
        return response()->json($this->admin->show($accessRequest));
    }

    public function createInvite(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $request->validate([
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $updated = $this->admin->createInvite($request->user(), $accessRequest);

        return response()->json([
            'data' => $updated,
            'message' => 'Invitation issued and account access request marked created.',
        ]);
    }

    public function ignore(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->admin->ignore($request->user(), $accessRequest, $validated['reason'] ?? null);

        return response()->json([
            'data' => $updated,
            'message' => 'Request ignored.',
        ]);
    }

    public function reject(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->admin->reject($request->user(), $accessRequest, $validated['reason'] ?? null);

        return response()->json([
            'data' => $updated,
            'message' => 'Request rejected.',
        ]);
    }

    public function bans(): JsonResponse
    {
        return response()->json($this->admin->listBans());
    }

    public function clearBan(Request $request, AccessRequestBan $ban): JsonResponse
    {
        $updated = $this->admin->clearBan($request->user(), $ban);

        return response()->json([
            'data' => $updated,
            'message' => 'Request ban cleared.',
        ]);
    }
}
