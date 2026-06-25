<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email', 'is_admin', 'created_at']);

        return response()->json(['data' => $users]);
    }

    public function updateAdmin(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'is_admin' => ['You cannot change your own role.'],
            ]);
        }

        $validated = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $user->is_admin = $validated['is_admin'];
        $user->save();

        return response()->json([
            'data' => $user->only(['id', 'name', 'email', 'is_admin', 'created_at']),
        ]);
    }
}
