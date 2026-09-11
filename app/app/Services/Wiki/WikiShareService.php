<?php

namespace App\Services\Wiki;

use App\Models\KnowledgeImage;
use App\Models\PortfolioProfile;
use App\Models\WikiPage;
use App\Models\WikiPageShare;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WikiShareService
{
    public function __construct(private WikiMarkdownRenderer $renderer) {}

    public function enable(WikiPage $page, PortfolioProfile $profile): array
    {
        abort_unless((int) $page->profile_id === (int) $profile->id, 404);

        return DB::transaction(function () use ($page): array {
            $active = $page->shares()->whereNull('revoked_at')->lockForUpdate()->first();
            if ($active) {
                return $this->format($active);
            }
            $token = Str::random(64);
            $share = WikiPageShare::query()->create([
                'page_id' => $page->id,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => Crypt::encryptString($token),
                'created_at' => now(),
            ]);

            return $this->format($share, $token);
        });
    }

    public function revoke(WikiPage $page, PortfolioProfile $profile): void
    {
        abort_unless((int) $page->profile_id === (int) $profile->id, 404);
        $page->shares()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function regenerate(WikiPage $page, PortfolioProfile $profile): array
    {
        return DB::transaction(function () use ($page, $profile): array {
            $this->revoke($page, $profile);

            return $this->enable($page, $profile);
        });
    }

    public function publicPage(string $token): array
    {
        $share = WikiPageShare::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->firstOrFail();
        $page = $share->page()->with('profile')->firstOrFail();

        return ['title' => $page->title, 'rendered_html' => $this->renderer->render($page->profile, $page->markdown, true, $page, $token)];
    }

    public function sharedImage(string $token, string $imageUuid): KnowledgeImage
    {
        $share = WikiPageShare::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->firstOrFail();

        return $share->page->images()->where('uuid', $imageUuid)->firstOrFail();
    }

    private function format(WikiPageShare $share, ?string $token = null): array
    {
        $token ??= Crypt::decryptString($share->token_encrypted);

        return ['shared' => true, 'url' => url('/wiki/shared/'.$token), 'created_at' => $share->created_at?->toISOString()];
    }
}
