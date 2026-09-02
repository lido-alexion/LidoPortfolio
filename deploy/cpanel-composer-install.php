<?php
/**
 * Install locked Composer dependencies without SSH.
 *
 * Upload to: public_html/portfolio/cpanel-composer-install.php
 * Visit:     https://www.lidoalexion.com/portfolio/cpanel-composer-install.php?token=YOUR_TOKEN
 *
 * The command is intentionally fixed: no command or package names are accepted
 * from the request. DELETE this file immediately after a successful run.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('max_execution_time', '600');
set_time_limit(600);
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';

if (! hash_equals(SETUP_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file, then visit ?token=YOUR_TOKEN\n");
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

$laravelRootCandidates = [
    dirname(__DIR__).'/lidoportfolio',
    dirname(__DIR__, 2).'/public_html/lidoportfolio',
];

$laravelRoot = null;
foreach ($laravelRootCandidates as $candidate) {
    if (is_file($candidate.'/composer.json') && is_file($candidate.'/composer.lock')) {
        $laravelRoot = realpath($candidate) ?: $candidate;
        break;
    }
}

if ($laravelRoot === null) {
    http_response_code(500);
    exit("Could not find Laravel composer.json and composer.lock.\n");
}

if (! function_exists('proc_open')) {
    http_response_code(500);
    exit("proc_open is disabled by the host; this helper cannot launch Composer.\n");
}

$composerCandidates = [
    '/opt/cpanel/composer/bin/composer',
    '/usr/local/bin/composer',
    '/usr/bin/composer',
    $laravelRoot.'/composer.phar',
];

$composer = null;
foreach ($composerCandidates as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $composer = $candidate;
        break;
    }
}

if ($composer === null) {
    http_response_code(500);
    echo "Composer was not found. Checked:\n";
    foreach ($composerCandidates as $candidate) {
        echo "  - {$candidate}\n";
    }
    echo "\nUpload composer.phar beside composer.json in the Laravel root, then retry.\n";
    exit(1);
}

$composerArguments = [
    'install',
    '--prefer-dist',
    '--optimize-autoloader',
    '--no-interaction',
    '--no-ansi',
];
$command = str_ends_with($composer, '.phar')
    ? array_merge([PHP_BINARY, $composer], $composerArguments)
    : array_merge([$composer], $composerArguments);

echo "=== Lido Portfolio Composer install ===\n\n";
echo "Laravel root: {$laravelRoot}\n";
echo 'PHP: '.PHP_VERSION."\n";
echo "Composer: {$composer}\n";
echo "Mode: locked dependencies; preserve existing vendor packages\n\n";

putenv('COMPOSER_HOME='.$laravelRoot.'/.composer');
putenv('COMPOSER_NO_INTERACTION=1');

$process = proc_open(
    $command,
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $laravelRoot,
    null,
);

if (! is_resource($process)) {
    http_response_code(500);
    exit("Unable to start Composer.\n");
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

do {
    $status = proc_get_status($process);
    foreach ([1, 2] as $pipe) {
        $output = stream_get_contents($pipes[$pipe]);
        if ($output !== false && $output !== '') {
            echo $output;
            flush();
        }
    }
    if ($status['running']) {
        usleep(100000);
    }
} while ($status['running']);

foreach ([1, 2] as $pipe) {
    $output = stream_get_contents($pipes[$pipe]);
    if ($output !== false && $output !== '') {
        echo $output;
    }
    fclose($pipes[$pipe]);
}

$exitCode = proc_close($process);
if ($exitCode === -1 && isset($status['exitcode']) && $status['exitcode'] >= 0) {
    $exitCode = $status['exitcode'];
}

echo "\n--- Verification ---\n";
$autoload = $laravelRoot.'/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$google2faReady = class_exists(PragmaRX\Google2FA\Google2FA::class);
$qrReady = class_exists(BaconQrCode\Writer::class);
echo 'PragmaRX Google2FA: '.($google2faReady ? 'OK' : 'MISSING')."\n";
echo 'BaconQrCode: '.($qrReady ? 'OK' : 'MISSING')."\n";

if ($exitCode !== 0 || ! $google2faReady || ! $qrReady) {
    echo "\nFAILED (Composer exit code {$exitCode}). Keep this output for diagnosis.\n";
    exit(1);
}

echo "\nAll checks passed. Retry Setup TOTP Authenticator now.\n";
echo "DELETE cpanel-composer-install.php from public_html/portfolio/ immediately after verification.\n";
