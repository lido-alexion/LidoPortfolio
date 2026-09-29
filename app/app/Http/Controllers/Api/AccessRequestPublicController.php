<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccessRequest\AccessRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessRequestPublicController extends Controller
{
    public function __construct(
        protected AccessRequestService $accessRequests,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'captcha_token' => ['required', 'string', 'max:4096'],
        ]);

        $result = $this->accessRequests->submitForVerification(
            $validated['full_name'],
            $validated['email'],
            $validated['captcha_token'],
            $request->ip(),
        );

        return response()->json($result);
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'data' => [
                'turnstile_site_key' => config('access_requests.captcha.turnstile.site_key'),
            ],
        ]);
    }

    public function verify(Request $request, string $token): JsonResponse
    {
        $result = $this->accessRequests->completeVerification($token);

        return response()->json($result);
    }
}
