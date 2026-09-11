<?php

namespace App\Services\Wiki;

use App\Models\PortfolioProfile;
use App\Models\WikiPage;
use App\Services\KnowledgeBoardImageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

final class WikiExportService
{
    public function __construct(private WikiPageService $pages, private KnowledgeBoardImageService $images) {}

    public function page(PortfolioProfile $profile, string $uuid): Response
    {
        $page = $this->pages->find($profile, $uuid);
        $markdown = $this->portableMarkdown($page, collect([$page]), [$page->id => $this->path($page)]);

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->safeSegment($page->slug).'.md"',
        ]);
    }

    public function archive(PortfolioProfile $profile, ?string $rootUuid = null): BinaryFileResponse
    {
        $root = $rootUuid ? $this->pages->find($profile, $rootUuid) : null;
        $all = WikiPage::query()->where('profile_id', $profile->id)->get();
        $included = $root ? $this->branch($root, $all) : $all;
        $paths = $included->mapWithKeys(fn (WikiPage $page) => [$page->id => $this->path($page)]);
        $temporary = tempnam(sys_get_temp_dir(), 'stox-wiki-');
        $archivePath = $temporary.'.zip';
        @unlink($temporary);
        $zip = new ZipArchive;
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($included as $page) {
            $zip->addFromString($paths[$page->id], $this->portableMarkdown($page, $included, $paths->all()));
            foreach ($this->referencedImages($page) as $image) {
                $path = $this->images->pathFor($image);
                if ($path) {
                    $zip->addFile($path, 'images/'.$image->uuid.'.'.pathinfo($image->display_filename, PATHINFO_EXTENSION));
                }
            }
        }
        $zip->close();

        return response()->download($archivePath, $root ? $this->safeSegment($root->slug).'-wiki-branch.zip' : 'stox-wiki.zip')->deleteFileAfterSend();
    }

    private function portableMarkdown(WikiPage $page, Collection $included, array $paths): string
    {
        $markdown = preg_replace_callback('/\[\[wiki:([0-9a-f-]{36})(?:\|([^\]]+))?\]\]/i', function (array $match) use ($page, $included, $paths): string {
            $target = $included->firstWhere('uuid', $match[1]);
            if (! $target) {
                return '**⚠ Unavailable Wiki Page**';
            }
            $text = trim($match[2] ?? '') ?: $target->title;

            return '['.$text.']('.$this->relativePath($paths[$page->id], $paths[$target->id]).')';
        }, $page->markdown) ?? '';

        return preg_replace_callback('/!\[([^\]]*)\]\(wiki-image:([0-9a-f-]{36})\)/i', function (array $match) use ($page): string {
            $image = $page->images->firstWhere('uuid', $match[2]);
            if (! $image) {
                return '**⚠ Missing Wiki Image**';
            }

            return '!['.$match[1].']('.$this->relativePath($this->path($page), 'images/'.$image->uuid.'.'.pathinfo($image->display_filename, PATHINFO_EXTENSION)).')';
        }, $markdown) ?? '';
    }

    private function referencedImages(WikiPage $page): Collection
    {
        preg_match_all('/wiki-image:([0-9a-f-]{36})/i', $page->markdown, $matches);

        return $page->images()->whereIn('uuid', array_unique($matches[1] ?? []))->get();
    }

    private function branch(WikiPage $root, Collection $all): Collection
    {
        $ids = collect([$root->id]);
        $frontier = collect([$root->id]);
        while ($frontier->isNotEmpty()) {
            $frontier = $all->whereIn('parent_id', $frontier)->pluck('id');
            $ids = $ids->merge($frontier);
        }

        return $all->whereIn('id', $ids)->values();
    }

    private function path(WikiPage $page): string
    {
        $segments = [];
        $cursor = $page;
        while ($cursor->parent_id && count($segments) < 100) {
            $cursor = WikiPage::query()->findOrFail($cursor->parent_id);
            array_unshift($segments, $this->safeSegment($cursor->slug).'-'.substr($cursor->uuid, 0, 8));
        }
        $segments[] = $this->safeSegment($page->slug).'-'.substr($page->uuid, 0, 8).'.md';

        return implode('/', $segments);
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', dirname($from) === '.' ? '' : dirname($from));
        $toParts = explode('/', $to);
        while ($fromParts && $toParts && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count(array_filter($fromParts))).implode('/', $toParts);
    }

    private function safeSegment(string $value): string
    {
        return Str::slug($value) ?: 'page';
    }
}
