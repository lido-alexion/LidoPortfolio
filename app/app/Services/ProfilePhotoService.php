<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProfilePhotoService
{
    public function directory(): string
    {
        return storage_path('app/profile-photos');
    }

    public function pathForUser(User $user): ?string
    {
        $relative = $user->profile_photo_path;
        if (! $relative) {
            return null;
        }

        $full = $this->directory().DIRECTORY_SEPARATOR.$relative;
        if (! is_file($full)) {
            return null;
        }

        return $full;
    }

    public function store(User $user, UploadedFile $file): string
    {
        $this->deleteFile($user);

        if (! File::isDirectory($this->directory())) {
            File::makeDirectory($this->directory(), 0755, true);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = $user->id.'_'.Str::uuid()->toString().'.'.$extension;
        $file->move($this->directory(), $filename);

        return $filename;
    }

    public function deleteFile(User $user): void
    {
        $path = $this->pathForUser($user);
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    public function mimeTypeForPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
