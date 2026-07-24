<?php

namespace App\Services;

use App\Models\KnowledgeImage;
use App\Models\PortfolioProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KnowledgeBoardImageService
{
    public const MAX_DISPLAY_EDGE = 1200;

    public const MAX_FULL_BYTES = 12 * 1024 * 1024;

    public const MAX_DISPLAY_BYTES = 4 * 1024 * 1024;

    /**
     * @param  array{
     *     display: UploadedFile,
     *     full: UploadedFile,
     *     original_name?: ?string,
     *     display_width?: ?int,
     *     display_height?: ?int,
     *     full_width?: ?int,
     *     full_height?: ?int
     * }  $payload
     * @return array<string, mixed>
     */
    public function store(PortfolioProfile $profile, array $payload): array
    {
        $display = $payload['display'];
        $full = $payload['full'];

        $this->assertImageFile($display, self::MAX_DISPLAY_BYTES, 'display');
        $this->assertImageFile($full, self::MAX_FULL_BYTES, 'full');

        $uuid = (string) Str::uuid();
        $dir = $this->directoryForProfile($profile);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $displayExt = $this->safeExtension($display);
        $fullExt = $this->safeExtension($full);
        $displayFilename = $uuid.'_display.'.$displayExt;
        $fullFilename = $uuid.'_full.'.$fullExt;

        $display->move($dir, $displayFilename);
        $full->move($dir, $fullFilename);

        $image = KnowledgeImage::query()->create([
            'profile_id' => $profile->id,
            'uuid' => $uuid,
            'original_name' => $this->truncateName($payload['original_name'] ?? $full->getClientOriginalName()),
            'mime_type' => $this->mimeFromExtension($displayExt),
            'display_filename' => $displayFilename,
            'full_filename' => $fullFilename,
            'display_width' => isset($payload['display_width']) ? (int) $payload['display_width'] : null,
            'display_height' => isset($payload['display_height']) ? (int) $payload['display_height'] : null,
            'full_width' => isset($payload['full_width']) ? (int) $payload['full_width'] : null,
            'full_height' => isset($payload['full_height']) ? (int) $payload['full_height'] : null,
            'display_bytes' => (int) (@filesize($dir.DIRECTORY_SEPARATOR.$displayFilename) ?: 0),
            'full_bytes' => (int) (@filesize($dir.DIRECTORY_SEPARATOR.$fullFilename) ?: 0),
        ]);

        return $this->format($image);
    }

    /**
     * @return array<string, mixed>
     */
    public function format(KnowledgeImage $image): array
    {
        return [
            'id' => $image->id,
            'uuid' => $image->uuid,
            'original_name' => $image->original_name,
            'mime_type' => $image->mime_type,
            'display_url' => '/api/knowledge-board/images/'.$image->uuid,
            'full_url' => '/api/knowledge-board/images/'.$image->uuid.'/full',
            'display_width' => $image->display_width,
            'display_height' => $image->display_height,
            'full_width' => $image->full_width,
            'full_height' => $image->full_height,
        ];
    }

    public function findForProfile(PortfolioProfile $profile, string $uuid): ?KnowledgeImage
    {
        return KnowledgeImage::query()
            ->where('profile_id', $profile->id)
            ->where('uuid', $uuid)
            ->first();
    }

    public function respond(KnowledgeImage $image, string $variant = 'display'): BinaryFileResponse
    {
        $path = $this->pathFor($image, $variant === 'full' ? 'full' : 'display');
        if ($path === null) {
            abort(404, 'Image not found.');
        }

        $mime = $this->mimeTypeForPath($path) ?: $image->mime_type;

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function directoryForProfile(PortfolioProfile $profile): string
    {
        return $this->directoryForProfileId((int) $profile->id);
    }

    public function directoryForProfileId(int $profileId): string
    {
        return storage_path('app/knowledge-images'.DIRECTORY_SEPARATOR.$profileId);
    }

    public function pathFor(KnowledgeImage $image, string $variant = 'display'): ?string
    {
        $filename = $variant === 'full' ? $image->full_filename : $image->display_filename;
        $full = $this->directoryForProfileId((int) $image->profile_id).DIRECTORY_SEPARATOR.$filename;
        if (! is_file($full)) {
            return null;
        }

        return $full;
    }

    public function mimeTypeForPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    protected function assertImageFile(UploadedFile $file, int $maxBytes, string $label): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $label => ['The '.$label.' image upload failed.'],
            ]);
        }

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                $label => ['The '.$label.' image may not be greater than '.(int) ($maxBytes / 1024 / 1024).' MB.'],
            ]);
        }

        $mime = $this->detectImageMime($file);
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                $label => ['The '.$label.' image must be a JPEG, PNG, WebP, or GIF.'],
            ]);
        }
    }

    protected function detectImageMime(UploadedFile $file): string
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        if (is_string($path) && is_file($path)) {
            $handle = @fopen($path, 'rb');
            if ($handle !== false) {
                $header = (string) fread($handle, 16);
                fclose($handle);
                if (str_starts_with($header, "\xFF\xD8\xFF")) {
                    return 'image/jpeg';
                }
                if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
                    return 'image/png';
                }
                if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
                    return 'image/gif';
                }
                if (strlen($header) >= 12 && str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
                    return 'image/webp';
                }
            }
        }

        $client = strtolower((string) ($file->getClientMimeType() ?: ''));
        if ($client !== '') {
            return $client;
        }

        return match ($this->safeExtension($file)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    protected function safeExtension(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $ext = 'jpg';
        }

        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    protected function mimeFromExtension(string $ext): string
    {
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    protected function truncateName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return Str::limit($name, 240, '');
    }
}
