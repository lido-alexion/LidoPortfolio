<?php
/**
 * Upload to public_html/portfolio/cpanel-diagnose.php — DELETE after use.
 * No Laravel. Confirms this PHP file runs (not rewritten to index.php).
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "=== Portfolio deploy diagnose ===\n\n";
echo "1) This script ran: YES\n";
echo "   __FILE__: ".__FILE__."\n";
echo "   PHP: ".PHP_VERSION."\n\n";

$requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'tokenizer', 'xml', 'ctype', 'json'];
echo "1b) PHP extensions (Laravel):\n";
foreach ($requiredExtensions as $ext) {
    echo '   '.($ext.': '.(extension_loaded($ext) ? 'yes' : '*** MISSING ***'))."\n";
}
echo "\n";

$root = dirname(__DIR__).'/lidoportfolio';
echo "2) Laravel root: {$root}\n";
echo "   exists: ".(is_dir($root) ? 'yes' : 'NO')."\n";
echo "   vendor/autoload.php: ".(is_file($root.'/vendor/autoload.php') ? 'yes' : 'NO')."\n";
echo "   .env: ".(is_file($root.'/.env') ? 'yes' : 'NO')."\n";
echo "   storage writable: ".(is_writable($root.'/storage') ? 'yes' : 'NO')."\n";
echo "   bootstrap/cache writable: ".(is_writable($root.'/bootstrap/cache') ? 'yes' : 'NO')."\n";

$configCache = $root.'/bootstrap/cache/config.php';
echo "   bootstrap/cache/config.php: ".(is_file($configCache) ? 'YES — delete this if DB user is wrong' : 'no')."\n";

$viteHot = $root.'/public/hot';
$viteManifest = $root.'/public/build/manifest.json';
$webBuild = dirname(__DIR__).'/portfolio/build/manifest.json';
echo "   public/hot (dev): ".(is_file($viteHot) ? 'YES — DELETE (causes blank page)' : 'no')."\n";
echo "   public/build/manifest.json: ".(is_file($viteManifest) ? 'yes' : 'MISSING — run npm run build and upload')."\n";
echo "   portfolio/build/manifest.json: ".(is_file($webBuild) ? 'yes' : 'MISSING — copy public/build to portfolio/build')."\n\n";

$sharedDbConfig = dirname(__DIR__, 2).'/config/DBConfig.php';
if (! is_file($sharedDbConfig)) {
    $sharedDbConfig = '/home/p7xatiz6j0mk/config/DBConfig.php';
}
$localDbConfig = $root.'/config/DBConfig.php';

echo "3) DBConfig.php paths\n";
echo "   shared (home): {$sharedDbConfig}\n";
echo "   exists: ".(is_file($sharedDbConfig) ? 'yes' : 'NO')."\n";
echo "   app-local: {$localDbConfig}\n";
echo "   exists: ".(is_file($localDbConfig) ? 'yes — often a dev copy with root; DELETE or rename' : 'no (good)')."\n";

if (is_file($root.'/config/load_db_config.php')) {
    require_once $root.'/config/load_db_config.php';
    $loaded = function_exists('portfolio_db_config_file') ? portfolio_db_config_file() : null;
    echo "   load_db_config would use: ".($loaded ?: '(none found)')."\n";
    if ($loaded && function_exists('portfolio_db_from_config')) {
        $c = portfolio_db_from_config();
        echo "   credentials from that file: user=".($c['username'] ?? '?')." db=".($c['database'] ?? '?')." host=".($c['host'] ?? '?')."\n";
    }
} else {
    echo "   load_db_config.php: MISSING in lidoportfolio/config/ — upload from repo\n";
}
echo "\n";

echo "4) Bootstrap test...\n";
if (! is_file($root.'/vendor/autoload.php')) {
    echo "   SKIP (no vendor)\n";
    exit;
}

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    echo "   Laravel bootstrap: OK\n";
    echo "   APP_ENV: ".config('app.env')."\n";
    echo "   APP_URL: ".config('app.url')."\n";
    echo "   session.path: ".config('session.path')." (expect /portfolio on production)\n";
    echo "   session.domain: ".(config('session.domain') ?: '(null)')."\n";
    echo "   sanctum/csrf-cookie URL: ".url('/sanctum/csrf-cookie')."\n";
    echo "   api/auth/login URL: ".url('/api/auth/login')."\n";
    echo "   app-base meta (for JS): ".rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?: '', '/')."\n";
    echo "   DB config file: ".(function_exists('portfolio_db_config_file') ? (portfolio_db_config_file() ?: '(not loaded)') : 'n/a')."\n";
    echo "   DB host (resolved): ".config('database.connections.mysql.host')."\n";
    echo "   DB name (resolved): ".config('database.connections.mysql.database')."\n";
    echo "   DB user (resolved): ".config('database.connections.mysql.username')."\n";
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        echo "   DB connection: OK\n";
    } catch (Throwable $dbE) {
        echo "   DB connection: FAILED\n";
        echo "   ".$dbE->getMessage()."\n";
    }
} catch (Throwable $e) {
    echo "   Laravel bootstrap: FAILED\n";
    echo $e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
}

echo "\n5) If step 1 is NO, .htaccess is routing to index.php — ensure cpanel-*.php files exist in portfolio/ folder.\n";
echo "6) If user is root: delete lidoportfolio/config/DBConfig.php and bootstrap/cache/config.php, then re-run.\n";
