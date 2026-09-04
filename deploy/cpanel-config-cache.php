<?php
/**
 * Run config:clear + config:cache from the browser (no SSH). DELETE after use.
 *
 * Upload to: public_html/portfolio/cpanel-config-cache.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-config-cache.php?token=YOUR_TOKEN
 *
 * Use after editing production .env (APP_URL, SESSION_DOMAIN, etc.).
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file.\n");
}

header('Content-Type: text/plain; charset=utf-8');

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
    echo "Could not find Laravel (vendor/autoload.php).\n";
    exit(1);
}

echo "=== config:cache ===\n\n";
echo "Laravel root: {$laravelRoot}\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function run_artisan(Illuminate\Contracts\Console\Kernel $kernel, string $label, string $command): void
{
    echo "{$label}... ";
    $kernel->call($command);
    echo "OK\n";
}

try {
    if (is_file($laravelRoot.'/bootstrap/cache/config.php')) {
        run_artisan($kernel, 'config:clear', 'config:clear');
    } else {
        echo "config:clear... skipped (no cached config)\n";
    }

    run_artisan($kernel, 'config:cache', 'config:cache');

    echo "\nResolved settings:\n";
    echo '  APP_URL: '.config('app.url')."\n";
    echo '  session.path: '.config('session.path')."\n";
    echo '  session.domain: '.(config('session.domain') ?: '(null)')."\n";

    echo "\nDone. DELETE cpanel-config-cache.php from public_html/portfolio/ now.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
}
