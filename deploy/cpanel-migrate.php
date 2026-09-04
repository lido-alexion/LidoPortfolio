<?php
/**
 * Run pending migrations + verify portfolio DB requirements (no SSH).
 * Repairs orphaned sync-log state (runs table without logs table).
 *
 * Upload to: public_html/portfolio/cpanel-migrate.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-migrate.php?token=YOUR_TOKEN
 *
 * DELETE this file from the server immediately after success.
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

const SETUP_TOKEN = 'Lido';

const SYNC_LOGS_MIGRATION = '2026_06_21_000002_create_portfolio_sync_logs_tables';

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

define('LARAVEL_ROOT', $laravelRoot);

echo "=== Lido Portfolio migrate + verify ===\n\n";
echo 'Laravel root: '.LARAVEL_ROOT."\n";
echo 'PHP: '.PHP_VERSION."\n\n";

/**
 * @return array{ok: bool, hint: string|null}
 */
function check_line(string $label, bool $ok, ?string $hint = null): array
{
    echo $label.': '.($ok ? 'OK' : 'MISSING / FAILED');
    if (! $ok && $hint) {
        echo " — {$hint}";
    }
    echo "\n";

    return ['ok' => $ok, 'hint' => $hint];
}

/**
 * @param  array<int, string>  $failures
 */
function record_failure(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "   *** {$message}\n";
}

function ensure_migration_recorded(string $migration): void
{
    $exists = Illuminate\Support\Facades\DB::table('migrations')
        ->where('migration', $migration)
        ->exists();

    if ($exists) {
        echo "   migration row already recorded: {$migration}\n";

        return;
    }

    $batch = (int) (Illuminate\Support\Facades\DB::table('migrations')->max('batch') ?? 0);

    Illuminate\Support\Facades\DB::table('migrations')->insert([
        'migration' => $migration,
        'batch' => $batch > 0 ? $batch : 1,
    ]);

    echo "   recorded migration: {$migration}\n";
}

function create_portfolio_sync_runs_table(): void
{
    Illuminate\Support\Facades\Schema::create('portfolio_sync_runs', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('job_name', 64);
        $table->string('status', 16)->default('running');
        $table->timestamp('started_at');
        $table->timestamp('finished_at')->nullable();
        $table->unsignedInteger('stocks_processed')->nullable();
        $table->unsignedInteger('failures')->nullable();
        $table->unsignedInteger('skipped')->nullable();
        $table->text('summary')->nullable();
        $table->index(['job_name', 'started_at']);
    });
}

function create_portfolio_sync_logs_table(): void
{
    Illuminate\Support\Facades\Schema::create('portfolio_sync_logs', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->uuid('run_id');
        $table->string('job_name', 64);
        $table->string('level', 16);
        $table->text('message');
        $table->json('context')->nullable();
        $table->timestamp('logged_at');
        $table->index('run_id');
        $table->index(['level', 'logged_at']);
        $table->index('logged_at');
        $table->foreign('run_id')->references('id')->on('portfolio_sync_runs')->cascadeOnDelete();
    });
}

/**
 * @return array<int, string>
 */
function repair_sync_log_tables(): array
{
    $messages = [];
    $hasRuns = Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_runs');
    $hasLogs = Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_logs');

    if ($hasRuns && $hasLogs) {
        $messages[] = 'Both sync log tables already exist.';

        return $messages;
    }

    if (! $hasRuns && ! $hasLogs) {
        $messages[] = 'Sync log tables absent — will rely on artisan migrate.';

        return $messages;
    }

    if ($hasRuns && ! $hasLogs) {
        echo "Repair: portfolio_sync_runs exists but portfolio_sync_logs is missing.\n";
        create_portfolio_sync_logs_table();
        ensure_migration_recorded(SYNC_LOGS_MIGRATION);
        $messages[] = 'Created portfolio_sync_logs and recorded migration.';

        return $messages;
    }

    if (! $hasRuns && $hasLogs) {
        $messages[] = 'Unexpected state: portfolio_sync_logs without portfolio_sync_runs.';

        return $messages;
    }

    return $messages;
}

$failures = [];

echo "--- 1) Host prerequisites ---\n";

$requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'tokenizer', 'xml', 'ctype', 'json'];
foreach ($requiredExtensions as $ext) {
    $result = check_line("   ext {$ext}", extension_loaded($ext), 'enable in cPanel PHP extensions');
    if (! $result['ok']) {
        record_failure($failures, "PHP extension missing: {$ext}");
    }
}

require_once __DIR__.'/cpanel-environment.php';
$environmentFile = lido_production_environment_file(LARAVEL_ROOT);
$envOk = $environmentFile !== null;
check_line('   environment', $envOk, 'create /home/USER/config/LidoPortfolio.env from .env.production.example');
if (! $envOk) {
    record_failure($failures, 'Missing production environment file');
}

$storageOk = is_writable(LARAVEL_ROOT.'/storage');
check_line('   storage writable', $storageOk, 'chmod 775 storage');
if (! $storageOk) {
    record_failure($failures, 'storage/ not writable');
}

$cacheOk = is_writable(LARAVEL_ROOT.'/bootstrap/cache');
check_line('   bootstrap/cache writable', $cacheOk, 'chmod 775 bootstrap/cache');
if (! $cacheOk) {
    record_failure($failures, 'bootstrap/cache not writable');
}

$viteHot = LARAVEL_ROOT.'/public/hot';
if (is_file($viteHot)) {
    record_failure($failures, 'public/hot exists — delete it (causes blank SPA)');
    echo "   public/hot: DELETE THIS FILE\n";
} else {
    echo "   public/hot: OK (absent)\n";
}

$singleFolder = realpath(LARAVEL_ROOT) === realpath(__DIR__.'/laravel');
$manifestLaravel = $singleFolder ? __DIR__.'/build/manifest.json' : LARAVEL_ROOT.'/public/build/manifest.json';
$manifestWeb = __DIR__.'/build/manifest.json';
check_line('   public/build/manifest.json', is_file($manifestLaravel), 'npm run build + upload');
check_line('   portfolio/build/manifest.json', is_file($manifestWeb), 'copy public/build to portfolio/build');

echo "\n--- 2) Bootstrap Laravel ---\n";

try {
    if (is_file(LARAVEL_ROOT.'/bootstrap/cache/config.php')) {
        echo "config:clear (stale cached config)... ";
        require LARAVEL_ROOT.'/vendor/autoload.php';
        $app = require_once LARAVEL_ROOT.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        $kernel->call('config:clear');
        echo "OK\n";
    } else {
        require LARAVEL_ROOT.'/vendor/autoload.php';
        $app = require_once LARAVEL_ROOT.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Bootstrap FAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}

$routeCacheFiles = glob(LARAVEL_ROOT.'/bootstrap/cache/routes*.php') ?: [];
if ($routeCacheFiles !== []) {
    echo 'route cache ('.count($routeCacheFiles).' file(s)) — clearing stale routes... ';
    foreach ($routeCacheFiles as $file) {
        @unlink($file);
    }
    try {
        $kernel->call('route:clear');
    } catch (Throwable) {
        // ignore
    }
    echo "OK\n";
}

echo "   APP_URL: ".config('app.url')."\n";
echo "   APP_ENV: ".config('app.env')."\n";

try {
    Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "   DB connection: OK\n";
    echo '   DB user: '.config('database.connections.mysql.username')."\n";
    echo '   DB name: '.config('database.connections.mysql.database')."\n";
} catch (Throwable $e) {
    record_failure($failures, 'Database connection failed');
    echo '   DB connection: FAILED — '.$e->getMessage()."\n";
}

echo "\n--- 3) Tables before migrate ---\n";

$requiredTables = [
    'portfolio_users',
    'sessions',
    'portfolio_stocks',
    'portfolio_transactions',
    'portfolio_holdings',
    'portfolio_stock_prices',
    'portfolio_portfolio_snapshots',
    'portfolio_settings',
    'portfolio_sync_runs',
    'portfolio_sync_logs',
    'portfolio_profiles',
    'portfolio_profile_settings',
    'portfolio_alerts',
];

$postMigrateOnlyTables = [
    'portfolio_profiles',
    'portfolio_profile_settings',
];

foreach ($requiredTables as $table) {
    if (in_array($table, $postMigrateOnlyTables, true)) {
        continue;
    }
    $exists = Illuminate\Support\Facades\Schema::hasTable($table);
    check_line("   {$table}", $exists);
}

$requiredColumns = [
    'portfolio_holdings' => ['total_fees'],
    'portfolio_stock_prices' => ['adjusted_close_price', 'provider_source'],
    'portfolio_transactions' => ['fees'],
];

$postMigrateColumns = [
    'portfolio_transactions' => ['profile_id'],
    'portfolio_holdings' => ['profile_id'],
    'portfolio_portfolio_snapshots' => ['profile_id'],
    'portfolio_alerts' => ['profile_id'],
];

echo "\n   Column checks:\n";
foreach ($requiredColumns as $table => $columns) {
    if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
        echo "   {$table}: (table missing)\n";
        continue;
    }
    foreach ($columns as $column) {
        $exists = Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        check_line("   {$table}.{$column}", $exists, 'pending migration');
    }
}

echo "\n--- 4) Repair sync log tables (if needed) ---\n";

foreach (repair_sync_log_tables() as $message) {
    echo "   {$message}\n";
}

echo "\n--- 5) artisan migrate --force ---\n";

try {
    echo 'migrate --force... ';
    $kernel->call('migrate', ['--force' => true]);
    echo "OK\n";
    $migrateOutput = trim($kernel->output());
    if ($migrateOutput !== '') {
        echo $migrateOutput."\n";
    }
} catch (Throwable $e) {
    record_failure($failures, 'migrate failed: '.$e->getMessage());
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
}

echo "\n--- 6) Post-migrate verification ---\n";

foreach ($requiredTables as $table) {
    $exists = Illuminate\Support\Facades\Schema::hasTable($table);
    $result = check_line("   {$table}", $exists);
    if (! $exists) {
        record_failure($failures, "Table still missing: {$table}");
    }
}

foreach ($requiredColumns as $table => $columns) {
    if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
        continue;
    }
    foreach ($columns as $column) {
        $exists = Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        $result = check_line("   {$table}.{$column}", $exists);
        if (! $exists) {
            record_failure($failures, "Column still missing: {$table}.{$column}");
        }
    }
}

foreach ($postMigrateColumns as $table => $columns) {
    if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
        continue;
    }
    foreach ($columns as $column) {
        $exists = Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        $result = check_line("   {$table}.{$column}", $exists);
        if (! $exists) {
            record_failure($failures, "Column still missing: {$table}.{$column}");
        }
    }
}

if (Illuminate\Support\Facades\Schema::hasTable('portfolio_user_settings')) {
    record_failure($failures, 'portfolio_user_settings should be dropped after multi-portfolio migration');
    check_line('   portfolio_user_settings dropped', false, 'run migrate 2026_06_29_000001');
} else {
    check_line('   portfolio_user_settings dropped', true);
}

echo "\n--- 6b) Trading OS (portfolio_tos_*) ---\n";

$tosMigrationFiles = [
    '2026_07_25_000002_create_portfolio_tos_engine_tables',
    '2026_07_25_000003_tos_mvp_review_and_orders',
    '2026_07_25_000004_publish_informational_recommendations',
    '2026_07_25_000005_recommendation_market_opinion_execution_plan',
];
foreach ($tosMigrationFiles as $migrationName) {
    $path = LARAVEL_ROOT.'/database/migrations/'.$migrationName.'.php';
    $onDisk = is_file($path);
    check_line("   migration file {$migrationName}.php", $onDisk, 'upload database/migrations/');
    if (! $onDisk) {
        record_failure($failures, "Missing TOS migration file: {$migrationName}.php");
    }
}

$tosTables = [
    'portfolio_tos_discovery_runs',
    'portfolio_tos_candidates',
    'portfolio_tos_evaluation_runs',
    'portfolio_tos_evaluation_results',
    'portfolio_tos_recommendations',
    'portfolio_tos_recommendation_reviews',
    'portfolio_tos_notifications',
    'portfolio_tos_orders',
    'portfolio_tos_order_transactions',
    'portfolio_tos_review_reports',
    'portfolio_tos_review_metrics',
    'portfolio_tos_pipeline_runs',
];
foreach ($tosTables as $table) {
    $exists = Illuminate\Support\Facades\Schema::hasTable($table);
    $result = check_line("   {$table}", $exists, 'run migrate or cpanel-repair-tos-schema.php?apply=1');
    if (! $exists) {
        record_failure($failures, "TOS table still missing: {$table}");
    }
}

if (Illuminate\Support\Facades\Schema::hasTable('portfolio_tos_recommendations')) {
    $hasRef = Illuminate\Support\Facades\Schema::hasColumn('portfolio_tos_recommendations', 'reference_price');
    $result = check_line('   portfolio_tos_recommendations.reference_price', $hasRef, 'migration 2026_07_25_000003');
    if (! $hasRef) {
        record_failure($failures, 'Column still missing: portfolio_tos_recommendations.reference_price');
    }
}

$syncLogReady = false;
$retentionDays = null;

if (class_exists(\App\Services\SyncLogService::class)) {
    $syncLogService = app(\App\Services\SyncLogService::class);
    $retentionDays = $syncLogService->retentionDays();
    $syncLogReady = $syncLogService->isEnabled();
    echo "\n   sync_log_retention_days: {$retentionDays}\n";
    echo '   SyncLogService enabled (both tables + retention > 0): '.($syncLogReady ? 'yes' : 'no')."\n";

    $syncLogRoutes = ['api/sync-logs', 'api/sync-logs/runs', 'api/sync-logs/export'];
    $registeredRoutes = [];
    foreach ($app->get('router')->getRoutes() as $route) {
        foreach ($syncLogRoutes as $needle) {
            if ($route->uri() === $needle) {
                $registeredRoutes[$needle] = true;
            }
        }
    }
    echo '   sync-logs API routes registered: '.(
        count($registeredRoutes) === count($syncLogRoutes) ? 'yes' : 'NO — upload routes/api.php + SyncLogController.php'
    )."\n";

    if ($retentionDays <= 0) {
        echo "   Note: retention 0 disables in-app sync log writes.\n";
    }

    if (Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_runs')) {
        $runCount = (int) Illuminate\Support\Facades\DB::table('portfolio_sync_runs')->count();
        $logCount = Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_logs')
            ? (int) Illuminate\Support\Facades\DB::table('portfolio_sync_logs')->count()
            : 0;
        echo "   portfolio_sync_runs rows: {$runCount}\n";
        echo "   portfolio_sync_logs rows: {$logCount}\n";

        if ($runCount > 0 && $logCount === 0 && $syncLogReady) {
            echo "   Note: runs exist without log lines — run another daily/stock-master sync.\n";
        }
    }
} else {
    record_failure($failures, 'SyncLogService class not found — upload latest app/ code');
}

echo "\n--- 7) config:cache ---\n";

try {
    echo 'config:cache... ';
    $kernel->call('config:cache');
    echo "OK\n";
} catch (Throwable $e) {
    record_failure($failures, 'config:cache failed');
    echo 'FAILED — '.$e->getMessage()."\n";
}

echo "\n=== Summary ===\n";

if ($failures === []) {
    echo "All checks passed.\n";
    if ($syncLogReady) {
        echo "In-app sync logs are ready. Run a daily price sync to populate log lines.\n";
    }
    echo "\nDELETE cpanel-migrate.php from public_html/portfolio/ now.\n";
    exit(0);
}

echo "Completed with ".count($failures)." issue(s):\n";
foreach ($failures as $failure) {
    echo " - {$failure}\n";
}
echo "\nFix the items above, re-upload code/migrations if needed, then run this script again.\n";
echo "DELETE cpanel-migrate.php after everything is green.\n";
exit(1);
