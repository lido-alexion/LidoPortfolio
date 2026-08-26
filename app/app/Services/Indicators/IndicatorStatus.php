<?php

namespace App\Services\Indicators;

/**
 * Lifecycle status of a Registry entry (SD-033).
 */
final class IndicatorStatus
{
    /** Implemented and available for declared capabilities. */
    public const ACTIVE = 'active';

    /** Registered but calculation is a neutral stub (e.g. sector_strength = 50). */
    public const STUB = 'stub';

    /** Metadata only — no calculator yet. */
    public const PLANNED = 'planned';

    /** Kept for aliases / history; not offered to new consumers. */
    public const DEPRECATED = 'deprecated';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ACTIVE => 'Active',
            self::STUB => 'Stub',
            self::PLANNED => 'Planned',
            self::DEPRECATED => 'Deprecated',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $status): bool
    {
        return isset(self::labels()[$status]);
    }
}
