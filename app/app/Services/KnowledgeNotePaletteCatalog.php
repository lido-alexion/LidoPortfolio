<?php

namespace App\Services;

/**
 * Fixed contrasting background + text palettes for Knowledge Board notes.
 *
 * @phpstan-type Palette array{id: string, label: string, background: string, text: string}
 */
class KnowledgeNotePaletteCatalog
{
    public const DEFAULT_ID = 'default';

    /**
     * @return list<Palette>
     */
    public static function all(): array
    {
        return [
            [
                'id' => self::DEFAULT_ID,
                'label' => 'Theme default',
                'background' => '',
                'text' => '',
            ],
            [
                'id' => 'slate',
                'label' => 'Slate',
                'background' => '#1e293b',
                'text' => '#f1f5f9',
            ],
            [
                'id' => 'paper',
                'label' => 'Paper',
                'background' => '#f7f4ef',
                'text' => '#1c1917',
            ],
            [
                'id' => 'ocean',
                'label' => 'Ocean',
                'background' => '#0c4a6e',
                'text' => '#e0f2fe',
            ],
            [
                'id' => 'forest',
                'label' => 'Forest',
                'background' => '#14532d',
                'text' => '#dcfce7',
            ],
            [
                'id' => 'ink',
                'label' => 'Ink',
                'background' => '#111111',
                'text' => '#f5f5f5',
            ],
            [
                'id' => 'sky',
                'label' => 'Sky',
                'background' => '#e0f2fe',
                'text' => '#0c4a6e',
            ],
            [
                'id' => 'moss',
                'label' => 'Moss',
                'background' => '#ecfccb',
                'text' => '#365314',
            ],
            [
                'id' => 'navy',
                'label' => 'Navy',
                'background' => '#172554',
                'text' => '#dbeafe',
            ],
            [
                'id' => 'mint',
                'label' => 'Mint',
                'background' => '#ccfbf1',
                'text' => '#134e4a',
            ],
            [
                'id' => 'ember',
                'label' => 'Ember',
                'background' => '#7c2d12',
                'text' => '#ffedd5',
            ],
            [
                'id' => 'charcoal',
                'label' => 'Charcoal',
                'background' => '#374151',
                'text' => '#f9fafb',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(static fn (array $p) => $p['id'], self::all());
    }

    public static function isValid(?string $id): bool
    {
        if ($id === null || $id === '') {
            return true;
        }

        return in_array($id, self::ids(), true);
    }

    public static function normalize(?string $id): string
    {
        if ($id === null || $id === '' || ! self::isValid($id)) {
            return self::DEFAULT_ID;
        }

        return $id;
    }

    /**
     * @return Palette|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $palette) {
            if ($palette['id'] === $id) {
                return $palette;
            }
        }

        return null;
    }
}
