<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthAuditService;
use App\Services\ProfilePhotoService;
use App\Services\SessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfilePhotoService $photos,
        protected SessionManagementService $sessions,
        protected AuthAuditService $authAudit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profilePayload($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $name = isset($validated['name']) ? trim((string) $validated['name']) : '';
        $user->name = $name;
        $user->save();

        return response()->json([
            'message' => 'Profile updated.',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user->password = $validated['password'];
        $user->save();

        // PD-006: keep this session; revoke others + rotate remember_token.
        $removed = $this->sessions->revokeOtherSessionsForCredentialChange(
            $user,
            $request->session()->getId()
        );
        $this->authAudit->logLogout($user, $request, 'others');

        return response()->json([
            'message' => 'Password updated. Other devices have been signed out. This device remains signed in.',
            'sessions_removed' => $removed,
        ]);
    }

    public function photo(Request $request): BinaryFileResponse|JsonResponse
    {
        $path = $this->photos->pathForUser($request->user());
        if (! $path) {
            return response()->json(['message' => 'Profile photo not found.'], 404);
        }

        return response()->file($path, [
            'Content-Type' => $this->photos->mimeTypeForPath($path),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $user->profile_photo_path = $this->photos->store($user, $validated['photo']);
        $user->save();

        return response()->json([
            'message' => 'Profile photo updated.',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->photos->deleteFile($user);
        $user->profile_photo_path = null;
        $user->save();

        return response()->json([
            'message' => 'Profile photo removed.',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function profilePayload(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_photo_url' => $user->profile_photo_url,
        ];
    }
}
