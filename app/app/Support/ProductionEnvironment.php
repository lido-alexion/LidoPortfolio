<?php

namespace App\Support;

final class ProductionEnvironment
{
    public const FILE_NAME = 'LidoPortfolio.env';

    public static function resolve(string $basePath, ?string $explicitPath = null): ?string
    {
        $candidates = [];
        if (is_string($explicitPath) && $explicitPath !== '') {
            $candidates[] = $explicitPath;
        }

        // Outermost first so /home/USER/config wins over any accidental copy
        // inside public_html/portfolio/laravel/config.
        for ($levelsUp = 7; $levelsUp >= 1; $levelsUp--) {
            $candidates[] = dirname($basePath, $levelsUp)
                .DIRECTORY_SEPARATOR.'config'
                .DIRECTORY_SEPARATOR.self::FILE_NAME;
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
