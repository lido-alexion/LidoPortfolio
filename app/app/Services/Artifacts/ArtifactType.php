<?php

namespace App\Services\Artifacts;

final class ArtifactType
{
    public const INDICATOR = 'indicator';

    public const SCREENER = 'screener';

    public const STRATEGY = 'strategy';

    public const SCHEMA_VERSION = '1.0';

    public const PACKAGE_FORMAT = 'stox.trading_artifacts';

    public const MINIMUM_ENGINE_VERSION = '1.1.0';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::INDICATOR, self::SCREENER, self::STRATEGY];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
