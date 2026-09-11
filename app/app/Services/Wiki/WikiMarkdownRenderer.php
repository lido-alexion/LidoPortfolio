<?php

namespace App\Services\Wiki;

use App\Models\PortfolioProfile;
use App\Models\WikiPage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

final class WikiMarkdownRenderer
{
    public function render(PortfolioProfile $profile, string $markdown, bool $public = false): string
    {
        $markdown = preg_replace_callback(
            '/\[\[wiki:([0-9a-f-]{36})(?:\|([^\]]+))?\]\]/i',
            function (array $match) use ($profile, $public): string {
                if (! Str::isUuid($match[1])) {
                    return '**⚠ Missing Wiki Page**';
                }
                $target = WikiPage::query()->where('profile_id', $profile->id)->where('uuid', $match[1])->first();
                if (! $target) {
                    return '**⚠ Missing Wiki Page**';
                }
                $text = $this->escapeMarkdown(trim($match[2] ?? '') ?: $target->title);
                if ($public) {
                    $share = $target->shares()->whereNull('revoked_at')->latest('id')->first();
                    if (! $share) {
                        return '**🔒 Private Wiki Page**';
                    }

                    return '['.$text.']('.url('/wiki/shared/'.Crypt::decryptString($share->token_encrypted)).')';
                }

                return '['.$text.']('.url('/knowledge-board/wiki/'.$target->uuid).')';
            },
            $markdown,
        ) ?? '';

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 100,
        ]);

        return (string) $converter->convert($markdown);
    }

    private function escapeMarkdown(string $value): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $value);
    }
}
