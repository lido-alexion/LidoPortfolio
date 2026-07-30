<?php

namespace App\Services\Artifacts;

final class ArtifactOrigin
{
    public const FACTORY = 'factory';

    public const USER = 'user';

    public const IMPORTED = 'imported';

    public const AI_ASSISTED = 'ai_assisted';

    public const FORK = 'fork';

    public const EXPORTED = 'exported';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FACTORY,
            self::USER,
            self::IMPORTED,
            self::AI_ASSISTED,
            self::FORK,
            self::EXPORTED,
        ];
    }

    public static function isValid(string $origin): bool
    {
        return in_array($origin, self::all(), true);
    }
}
