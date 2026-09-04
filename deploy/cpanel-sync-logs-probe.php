<?php
/**
 * Diagnose + fix sync-logs API returning HTML (no SSH). DELETE after use.
 *
 * Upload to: public_html/portfolio/cpanel-sync-logs-probe.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-sync-logs-probe.php?token=YOUR_TOKEN
 * Auto-fix:  add &fix=1 to clear stale route cache (safe; does not run route:cache)
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';

$token = $_GET['token'] ?? '';
$doFix = isset($_GET['fix']) && $_GET['fix'] === '1';

if ($token !== SETUP_TOKEN) {
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
    echo "Could not find Laravel.\n";
    exit(1);
}

echo "=== Sync logs API probe ===\n\n";
echo "Laravel root: {$laravelRoot}\n";
echo 'Fix mode: '.($doFix ? 'ON' : 'off (add &fix=1 to clear route cache)')."\n\n";

function file_contains(string $path, string $needle): ?bool
{
    if (! is_file($path)) {
        return null;
    }

    return str_contains((string) file_get_contents($path), $needle);
}

echo "--- 1) Route file locations (common upload mistakes) ---\n";

$paths = [
    'CORRECT routes/api.php' => $laravelRoot.'/routes/api.php',
    'WRONG app/routes/api.php (delete if present)' => $laravelRoot.'/app/routes/api.php',
    'Controller' => $laravelRoot.'/app/Http/Controllers/Api/SyncLogController.php',
    'web.php' => $laravelRoot.'/routes/web.php',
];

foreach ($paths as $label => $path) {
    $exists = is_file($path);
    echo '   '.$label.': '.($exists ? 'yes' : 'missing');
    if ($exists && str_ends_with($path, 'api.php')) {
        $hasSyncLogs = file_contains($path, 'sync-logs');
        echo $hasSyncLogs ? ' (contains sync-logs)' : ' *** NO sync-logs lines in this file ***';
    }
    if ($exists && str_ends_with($path, 'web.php')) {
        $content = (string) file_get_contents($path);
        echo str_contains($content, '^(?!api') ? ' (api excluded from SPA fallback)' : ' *** OLD SPA catch-all — upload routes/web.php ***';
    }
    echo "\n";
}

echo "\n--- 2) Route cache (stale cache ignores updated routes/api.php) ---\n";
$routeCacheFiles = glob($laravelRoot.'/bootstrap/cache/routes*.php') ?: [];
if ($routeCacheFiles === []) {
    echo "   No route cache files (good).\n";
} else {
    foreach ($routeCacheFiles as $file) {
        echo '   FOUND: '.basename($file).' — Laravel may ignore routes/api.php changes'."\n";
        if ($doFix) {
            unlink($file);
            echo '      deleted '.basename($file)."\n";
        }
    }
    if (! $doFix) {
        echo "   Re-run with &fix=1 to delete these files.\n";
    }
}

require $laravelRoot.'/vendor/autoload.php';
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ($doFix && $routeCacheFiles !== []) {
    echo "\n   route:clear... ";
    try {
        $kernel->call('route:clear');
        echo "OK\n";
    } catch (Throwable $e) {
        echo 'skipped ('.$e->getMessage().")\n";
    }
}

echo "\n--- 3) Registered routes (after cache clear) ---\n";
$needles = ['api/sync-logs', 'api/sync-logs/runs', 'api/settings'];
foreach ($needles as $needle) {
    $found = false;
    foreach ($app->get('router')->getRoutes() as $route) {
        if ($route->uri() === $needle) {
            $found = true;
            break;
        }
    }
    echo '   '.$needle.': '.($found ? 'registered' : '*** NOT REGISTERED ***')."\n";
}

echo "\n--- 4) Simulated browser request (Apache subdirectory) ---\n";
$documentRoot = dirname($laravelRoot);
$_SERVER = array_merge($_SERVER, [
    'DOCUMENT_ROOT' => $documentRoot,
    'REQUEST_URI' => '/portfolio/api/sync-logs',
    'SCRIPT_NAME' => '/portfolio/index.php',
    'SCRIPT_FILENAME' => $documentRoot.'/portfolio/index.php',
    'PHP_SELF' => '/portfolio/index.php',
    'REQUEST_METHOD' => 'GET',
    'HTTP_HOST' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
    'HTTP_ACCEPT' => 'application/json',
    'SERVER_NAME' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
    'SERVER_PORT' => '443',
    'HTTPS' => 'on',
]);

$request = Illuminate\Http\Request::capture();
$response = $app->handle($request);
$contentType = (string) $response->headers->get('Content-Type');
$body = (string) $response->getContent();
$isJson = str_contains($contentType, 'json') || str_starts_with(ltrim($body), '{');

echo '   path(): '.$request->path()."\n";
echo '   status: '.$response->getStatusCode()."\n";
echo '   content-type: '.$contentType.($isJson ? ' (JSON — good)' : ' (HTML — bad)')."\n";
if ($isJson && strlen($body) < 400) {
    echo '   body: '.$body."\n";
}

echo "\n--- 5) Database ---\n";
if (Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_logs')) {
    echo '   log rows: '.(int) Illuminate\Support\Facades\DB::table('portfolio_sync_logs')->count()."\n";
}

echo "\n=== What to do ===\n";
if (! $isJson) {
    echo "1. Upload routes/api.php to lidoportfolio/routes/api.php (NOT lidoportfolio/app/routes/).\n";
    echo "2. Upload SyncLogController + SyncLog + SyncRun models.\n";
    echo "3. Upload routes/web.php (excludes api/* from SPA fallback).\n";
    echo "4. Visit this page with &fix=1 to delete stale route cache.\n";
    echo "5. Hard-refresh browser (Ctrl+Shift+R).\n";
} else {
    echo "API routing looks correct. If the UI is still empty, hard-refresh the browser.\n";
    echo "Expected when logged in: GET /portfolio/api/sync-logs returns 200 JSON.\n";
}
echo "\nDELETE cpanel-sync-logs-probe.php after use.\n";
