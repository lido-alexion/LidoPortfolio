<?php
/**
 * Server-side API probe — shows why /api/auth/me returns 500. DELETE after use.
 * Upload: public_html/portfolio/cpanel-api-probe.php
 * Visit:  https://lidoalexion.com/portfolio/cpanel-api-probe.php?token=YOUR_TOKEN
 */
declare(strict_types=1);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden.\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__).'/lidoportfolio';
echo "=== API probe ===\n\n";

if (! is_file($root.'/vendor/autoload.php')) {
    echo "Laravel not found at {$root}\n";
    exit(1);
}

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    echo 'APP_URL: '.config('app.url')."\n";
    echo 'session.driver: '.config('session.driver')."\n";
    echo 'session.table: '.config('session.table')."\n";
    echo 'session.path: '.config('session.path')."\n\n";

    $table = config('session.table', 'sessions');
    $hasSessions = Illuminate\Support\Facades\Schema::hasTable($table);
    echo "sessions table ({$table}): ".($hasSessions ? 'exists' : '*** MISSING — run cpanel-migrate.php ***')."\n\n";

    if ($hasSessions) {
        try {
            $count = Illuminate\Support\Facades\DB::table($table)->count();
            echo "sessions row count: {$count}\n\n";
        } catch (Throwable $e) {
            echo 'sessions query FAILED: '.$e->getMessage()."\n\n";
        }
    }

    echo "Internal GET /api/auth/me (guest):\n";
    $request = Illuminate\Http\Request::create('/api/auth/me', 'GET', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_HOST' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
    ]);
    $response = $app->handle($request);
    echo 'HTTP '.$response->getStatusCode()."\n";
    echo $response->getContent()."\n\n";

    echo "If status is 500 above, check lidoportfolio/storage/logs/laravel-*.log\n";
} catch (Throwable $e) {
    echo "FAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
}
