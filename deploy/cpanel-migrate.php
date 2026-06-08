<?php
/**
 * Run pending migrations from the browser (no SSH). DELETE after use.
 *
 * Upload to: public_html/portfolio/cpanel-migrate.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-migrate.php?token=YOUR_TOKEN
 *
 * Use after uploading new files under database/migrations/ (code updates).
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'CHANGE_ME_before_upload';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file.\n");
}

header('Content-Type: text/plain; charset=utf-8');

$laravelRootCandidates = [
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

echo "=== migrate (pending only) ===\n\n";
echo "Laravel root: {$laravelRoot}\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "migrate --force... ";
    $kernel->call('migrate', ['--force' => true]);
    echo "OK\n\n";
    echo trim($kernel->output())."\n\n";

    echo "config:cache... ";
    $kernel->call('config:cache');
    echo "OK\n\n";

    echo "Done. DELETE cpanel-migrate.php from public_html/portfolio/ now.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
}
