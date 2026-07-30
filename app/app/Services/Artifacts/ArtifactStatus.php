<?php

namespace App\Services\Artifacts;

final class ArtifactStatus
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const DEPRECATED = 'deprecated';

    public const ARCHIVED = 'archived';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DRAFT, self::ACTIVE, self::DEPRECATED, self::ARCHIVED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
