<?php

/**
 * Load shared MySQL credentials — aligned with legacy DB.php on lidoalexion.com.
 * When /home/.../config/DBConfig.php is found, it wins over .env DB_* (single source of truth).
 */
$GLOBALS['portfolioDbConfigPath'] = null;

$candidatePaths = [];

if (function_exists('env')) {
    $explicit = env('DB_CONFIG_PATH');
    if (is_string($explicit) && $explicit !== '') {
        $candidatePaths[] = $explicit;
    }
}

// Outermost paths first so shared /home/USER/config/DBConfig.php wins over
// a dev copy accidentally left in public_html/lidoportfolio/config/DBConfig.php.
for ($levelsUp = 7; $levelsUp >= 0; $levelsUp--) {
    $candidatePaths[] = dirname(__DIR__, $levelsUp + 1).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'DBConfig.php';
}

foreach ($candidatePaths as $path) {
    if (is_string($path) && is_file($path)) {
        require_once $path;
        $GLOBALS['portfolioDbConfigPath'] = $path;
        break;
    }
}

if (! function_exists('portfolio_db_config_file')) {
    function portfolio_db_config_file(): ?string
    {
        return $GLOBALS['portfolioDbConfigPath'] ?? null;
    }
}

if (! function_exists('portfolio_db_from_config')) {
    /**
     * @return array{host?: string, port?: string, database?: string, username?: string, password?: string}
     */
    function portfolio_db_from_config(): array
    {
        if (class_exists('DBConfig', false)) {
            $config = [
                'host' => DBConfig::getDBServer(),
                'database' => DBConfig::getDBName(),
                'username' => DBConfig::getDBUsername(),
                'password' => DBConfig::getDBPassword(),
            ];

            if (method_exists('DBConfig', 'getDBPort')) {
                $config['port'] = (string) DBConfig::getDBPort();
            }

            return $config;
        }

        $fromConstants = [];

        if (defined('DB_HOST')) {
            $fromConstants['host'] = DB_HOST;
        }
        if (defined('DB_NAME')) {
            $fromConstants['database'] = DB_NAME;
        }
        if (defined('DB_USER')) {
            $fromConstants['username'] = DB_USER;
        }
        if (defined('DB_PASS')) {
            $fromConstants['password'] = DB_PASS;
        }
        if (defined('DB_PORT')) {
            $fromConstants['port'] = (string) DB_PORT;
        }

        return $fromConstants;
    }
}

if (! function_exists('portfolio_db_setting')) {
    /**
     * @param  'host'|'port'|'database'|'username'|'password'  $configKey
     */
    function portfolio_db_setting(string $envKey, string $configKey, mixed $default = null): mixed
    {
        if (portfolio_db_config_file() !== null) {
            $fromFile = portfolio_db_from_config();
            if (array_key_exists($configKey, $fromFile)) {
                return $fromFile[$configKey];
            }
        }

        $fromEnv = env($envKey);
        if ($fromEnv !== null && $fromEnv !== '') {
            return $fromEnv;
        }

        $fromFile = portfolio_db_from_config();

        return $fromFile[$configKey] ?? $default;
    }
}
