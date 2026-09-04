<?php
/**
 * Run Data Quality Center maintenance commands (no SSH).
 *
 * Upload to: public_html/portfolio/cpanel-data-quality-center.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-data-quality-center.php?token=YOUR_TOKEN&task=scan
 *
 * Tasks:
 * - task=sync      -> portfolio:sync-corporate-actions
 * - task=scan      -> portfolio:detect-corporate-action-anomalies
 * - task=auto      -> portfolio:auto-resolve-data-quality-issues
 * - task=migrate   -> portfolio:migrate-legacy-corporate-actions (dry-run)
 * - task=migrate&apply=1 -> migrate legacy records
 *
 * DELETE this file from the server immediately after use.
 */
declare(strict_types=1);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file, then visit ?token=YOUR_TOKEN\n");
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

require $laravelRoot.'/vendor/autoload.php';
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$task = strtolower(trim((string) ($_GET['task'] ?? 'scan')));
$apply = (string) ($_GET['apply'] ?? '0') === '1';

$command = match ($task) {
    'sync' => ['portfolio:sync-corporate-actions', []],
    'scan' => ['portfolio:detect-corporate-action-anomalies', []],
    'auto' => ['portfolio:auto-resolve-data-quality-issues', []],
    'migrate' => ['portfolio:migrate-legacy-corporate-actions', $apply ? ['--apply' => true] : []],
    default => null,
};

if ($command === null) {
    echo "Unknown task. Use task=sync|scan|auto|migrate\n";
    exit(1);
}

[$name, $args] = $command;
echo "=== Data Quality Center ===\n";
echo "Laravel root: {$laravelRoot}\n";
echo "Task: {$task}\n";
echo "Apply flag: ".($apply ? '1' : '0')."\n\n";
echo "Running {$name} ...\n\n";

$exitCode = $kernel->call($name, $args);
echo trim($kernel->output())."\n\n";
echo "Exit code: {$exitCode}\n";
echo "DELETE cpanel-data-quality-center.php after success.\n";

exit($exitCode);
