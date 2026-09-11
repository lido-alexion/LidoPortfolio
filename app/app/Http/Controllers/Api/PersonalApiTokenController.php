<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalApiTokenController extends Controller
{
    public const SCOPES = [
        'portfolio:read',
        'portfolio:write',
        'execution:read',
        'execution:submit',
        'notes:read',
        'notes:write',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->tokens()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities ?? [],
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),
            'meta' => ['available_scopes' => self::SCOPES],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(self::SCOPES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $plain = $request->user()->createToken(
            $validated['name'],
            array_values(array_unique($validated['abilities'])),
            isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
        );

        return response()->json([
            'data' => [
                'id' => $plain->accessToken->id,
                'name' => $plain->accessToken->name,
                'abilities' => $plain->accessToken->abilities ?? [],
                'token' => $plain->plainTextToken,
                'expires_at' => $plain->accessToken->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        if ($deleted < 1) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        return response()->json(['message' => 'Token revoked']);
    }
}
