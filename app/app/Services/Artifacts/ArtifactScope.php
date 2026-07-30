<?php

namespace App\Services\Artifacts;

final class ArtifactScope
{
    public const SYSTEM = 'system';

    public const PORTFOLIO = 'portfolio';

    public const USER = 'user';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SYSTEM, self::PORTFOLIO, self::USER];
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }
}
