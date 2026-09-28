<?php
/**
 * FEAT-052 — verify LidoTelemetry OTLP export reaches the configured Collector.
 *
 * Upload: public_html/portfolio/cpanel-lido-telemetry-probe.php
 * Visit:  https://YOUR_DOMAIN/portfolio/cpanel-lido-telemetry-probe.php?token=YOUR_TOKEN
 *         Add &send=1 to POST a probe span (requires LIDO_TELEMETRY_ENABLED=true and endpoint set).
 *
 * DELETE this file after success.
 */
declare(strict_types=1);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN, then visit ?token=YOUR_TOKEN\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = is_file(__DIR__.'/laravel/vendor/autoload.php') ? __DIR__.'/laravel' : dirname(__DIR__).'/lidoportfolio';
echo "=== LidoTelemetry collector probe (FEAT-052) ===\n\n";

if (! is_file($root.'/vendor/autoload.php')) {
    echo "Laravel not found at {$root}\n";
    exit(1);
}

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $enabled = (bool) config('lido_telemetry.enabled', false);
    $endpoint = config('lido_telemetry.otlp_traces_endpoint');
    $metricsEndpoint = config('lido_telemetry.otlp_metrics_endpoint');
    echo 'LIDO_TELEMETRY_ENABLED: '.($enabled ? 'true' : 'false')."\n";
    echo 'LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT: '.(is_string($endpoint) && $endpoint !== '' ? $endpoint : '(not set)')."\n";
    echo 'LIDO_TELEMETRY_OTLP_METRICS_ENDPOINT: '.(is_string($metricsEndpoint) && $metricsEndpoint !== '' ? $metricsEndpoint : '(not set)')."\n";
    echo 'service: '.config('lido_telemetry.service_name').' @ '.config('lido_telemetry.environment')."\n\n";

    if (! $enabled) {
        echo "Telemetry is disabled. Set LIDO_TELEMETRY_ENABLED=true in production .env, then re-run with &send=1.\n";
        exit(0);
    }

    if (! is_string($endpoint) || trim($endpoint) === '') {
        echo "Endpoint missing. Set LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT to your Collector OTLP/HTTP traces URL.\n";
        exit(1);
    }

    if (($_GET['send'] ?? '') !== '1') {
        echo "Dry run only. Add &send=1 to export a probe span named stox.telemetry.collector_probe.\n";
        exit(0);
    }

    $telemetry = $app->make(App\Telemetry\LidoTelemetry::class);
    $telemetry->recordBusinessEvent(App\Telemetry\LidoTelemetryCatalog::BUSINESS_TELEMETRY_COLLECTOR_PROBE, [
        'probe' => 'cpanel',
        'sent_at' => gmdate('c'),
    ]);

    echo "Probe span dispatched (fail-open exporter; confirm receipt in LidoTelemetry / Collector logs).\n";
    echo "DELETE cpanel-lido-telemetry-probe.php after validation.\n";
} catch (Throwable $e) {
    echo 'FAILED: '.$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
