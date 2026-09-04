<?php
/**
 * ONE-TIME cPanel setup (no SSH). DELETE IMMEDIATELY AFTER SUCCESS.
 * Guide: deploy/DEPLOY.md
 *
 * Upload to: public_html/portfolio/cpanel-once-setup.php
 * Visit: /portfolio/cpanel-once-setup.php?token=YOUR_TOKEN
 *
 * Runs config:cache only (not route:cache — subdirectory /portfolio can break).
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (! headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
    }
    echo "\n\nPHP FATAL: {$error['message']}\n{$error['file']}:{$error['line']}\n";
});

const SETUP_TOKEN = 'CHANGE_ME_before_upload';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file.\n");
}

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><body><pre>';
echo "Setup script started (PHP ".PHP_VERSION.")\n";
flush();

$laravelRootCandidates = [
    __DIR__.'/laravel',
    dirname(__DIR__).'/lidoportfolio',
    dirname(__DIR__, 2).'/public_html/lidoportfolio',
];

$laravelRoot = null;
foreach ($laravelRootCandidates as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php')) {
        $laravelRoot = $candidate;
        break;
    }
}

if ($laravelRoot === null) {
    http_response_code(500);
    echo "Could not find vendor/autoload.php. Tried:\n";
    foreach ($laravelRootCandidates as $candidate) {
        echo '  - '.htmlspecialchars($candidate)."\n";
    }
    echo '</pre></body></html>';
    exit(1);
}

define('LARAVEL_ROOT', $laravelRoot);
echo 'Laravel root: '.htmlspecialchars(LARAVEL_ROOT)."\n";
flush();

function setup_step(string $label, callable $run): void
{
    echo htmlspecialchars($label).'... ';
    flush();
    try {
        $run();
        echo "OK\n";
        flush();
    } catch (Throwable $e) {
        http_response_code(500);
        echo "FAILED\n";
        echo htmlspecialchars($e->getMessage())."\n\n";
        echo htmlspecialchars($e->getTraceAsString())."\n";
        echo '</pre></body></html>';
        exit(1);
    }
}

try {
    require_once __DIR__.'/cpanel-environment.php';
    if (lido_production_environment_file(LARAVEL_ROOT) === null) {
        throw new RuntimeException('Missing /home/USER/config/LidoPortfolio.env (or legacy Laravel .env).');
    }

    if (! is_writable(LARAVEL_ROOT.'/storage')) {
        throw new RuntimeException('storage/ is not writable — set permissions to 775');
    }

    require LARAVEL_ROOT.'/vendor/autoload.php';

    /** @var Illuminate\Foundation\Application $app */
    $app = require_once LARAVEL_ROOT.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $output = [];

    setup_step('config:clear (reset cached DB)', function () use ($kernel): void {
        if (is_file(LARAVEL_ROOT.'/bootstrap/cache/config.php')) {
            $kernel->call('config:clear');
        }
    });

    setup_step('key:generate', function () use ($kernel, &$output): void {
        $kernel->call('key:generate', ['--force' => true]);
        $output[] = trim($kernel->output());
    });

    setup_step('migrate', function () use ($kernel, &$output): void {
        $kernel->call('migrate', ['--force' => true]);
        $output[] = trim($kernel->output());
    });

    setup_step('config:cache', function () use ($kernel): void {
        $kernel->call('config:cache');
    });

    // route:cache can break subdirectory deploy — skip unless you need it
    setup_step('storage:link (optional)', function () use ($kernel): void {
        if (is_dir(LARAVEL_ROOT.'/public/storage')) {
            return;
        }
        try {
            $kernel->call('storage:link');
        } catch (Throwable) {
            // ignore
        }
    });

    echo "\n--- artisan output ---\n";
    echo htmlspecialchars(implode("\n", array_filter($output)));
    echo "\n\nSetup complete.\n";
    echo "DELETE cpanel-once-setup.php and cpanel-diagnose.php NOW.\n";
    echo "Open: https://lidoalexion.com/portfolio/\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nSETUP ERROR:\n";
    echo htmlspecialchars($e->getMessage())."\n\n";
    echo htmlspecialchars($e->getTraceAsString())."\n";
    echo "\nAlso check: ".htmlspecialchars(LARAVEL_ROOT.'/storage/logs/')."\n";
}

echo '</pre></body></html>';
