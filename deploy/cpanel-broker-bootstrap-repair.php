<?php
/**
 * Diagnose the broker provider registration and clear stale compiled provider metadata.
 *
 * Upload to: public_html/portfolio/cpanel-broker-bootstrap-repair.php
 * Visit:     https://www.lidoalexion.com/portfolio/cpanel-broker-bootstrap-repair.php?token=YOUR_TOKEN
 *
 * DELETE this file immediately after use.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';

if (! hash_equals(SETUP_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file, then visit ?token=YOUR_TOKEN\n");
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$rootCandidates = [
    __DIR__.'/laravel',
    dirname(__DIR__).'/lidoportfolio',
    dirname(__DIR__, 2).'/public_html/lidoportfolio',
];

$root = null;
foreach ($rootCandidates as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php')) {
        $root = realpath($candidate) ?: $candidate;
        break;
    }
}

if ($root === null) {
    http_response_code(500);
    exit("Could not find Laravel root.\n");
}

echo "=== Broker provider bootstrap repair ===\n\n";
echo "Laravel root: {$root}\n";
echo 'PHP: '.PHP_VERSION."\n\n";

$providerClass = App\Providers\BrokerServiceProvider::class;
$gatewayClass = App\Services\Broker\BrokerGateway::class;
$providerFile = $root.'/app/Providers/BrokerServiceProvider.php';
$providersFile = $root.'/bootstrap/providers.php';

echo 'BrokerServiceProvider.php: '.(is_file($providerFile) ? 'OK' : 'MISSING')."\n";
echo 'bootstrap/providers.php: '.(is_file($providersFile) ? 'OK' : 'MISSING')."\n";

if (! is_file($providerFile) || ! is_file($providersFile)) {
    http_response_code(500);
    exit("\nFAILED: upload the complete staged lidoportfolio payload first.\n");
}

require $root.'/vendor/autoload.php';

$providers = require $providersFile;
$registeredInSource = is_array($providers) && in_array($providerClass, $providers, true);
echo 'BrokerServiceProvider registered in source: '.($registeredInSource ? 'OK' : 'MISSING')."\n";

if (! $registeredInSource) {
    http_response_code(500);
    exit("\nFAILED: deployed bootstrap/providers.php does not register BrokerServiceProvider.\n");
}

/** @var Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'BrokerGateway bound before repair: '.($app->bound($gatewayClass) ? 'YES' : 'NO')."\n";
echo "clear-compiled... ";
$exitCode = $kernel->call('clear-compiled');
echo $exitCode === 0 ? "OK\n" : "FAILED ({$exitCode})\n";

if ($exitCode !== 0) {
    http_response_code(500);
    echo $kernel->output();
    exit(1);
}

echo "\nCompiled provider metadata cleared. Reload Review Dashboard now.\n";
echo "DELETE cpanel-broker-bootstrap-repair.php from public_html/portfolio/ after verification.\n";
