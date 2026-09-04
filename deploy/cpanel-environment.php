<?php

declare(strict_types=1);

if (! function_exists('lido_production_environment_file')) {
    function lido_production_environment_file(string $laravelRoot): ?string
    {
        $candidates = [];
        $explicit = getenv('LIDO_ENV_PATH');
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }

        for ($levelsUp = 7; $levelsUp >= 1; $levelsUp--) {
            $candidates[] = dirname($laravelRoot, $levelsUp).'/config/LidoPortfolio.env';
        }

        // Legacy layout fallback only. New single-folder releases keep secrets
        // outside public_html.
        $candidates[] = $laravelRoot.'/.env';

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
