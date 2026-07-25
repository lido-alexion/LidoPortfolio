<?php
/**
 * Diagnose / repair Trading OS (portfolio_tos_*) schema (no SSH).
 *
 * Default = scan only. Apply migrations with &apply=1.
 *
 * Upload to: public_html/portfolio/cpanel-repair-tos-schema.php
 * Scan:  https://YOUR-DOMAIN/portfolio/cpanel-repair-tos-schema.php?token=YOUR_TOKEN
 * Apply: https://YOUR-DOMAIN/portfolio/cpanel-repair-tos-schema.php?token=YOUR_TOKEN&apply=1
 *
 * DELETE this file from the server after success.
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

$tosMigrations = [
    '2026_07_25_000002_create_portfolio_tos_engine_tables',
    '2026_07_25_000003_tos_mvp_review_and_orders',
    '2026_07_25_000004_publish_informational_recommendations',
    '2026_07_25_000005_recommendation_market_opinion_execution_plan',
];

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

$tosColumns = [
    'portfolio_tos_recommendations' => ['reference_price'],
    'portfolio_tos_orders' => ['cancelled_at', 'limit_price', 'notes'],
];

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file, then visit ?token=YOUR_TOKEN\n");
}

$apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

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

echo "=== Trading OS schema repair ===\n\n";
echo 'Laravel root: '.$laravelRoot."\n";
echo 'PHP: '.PHP_VERSION."\n";
echo 'Mode: '.($apply ? 'APPLY (migrate --force)' : 'SCAN only (add &apply=1 to write)')."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$failures = [];

echo "--- 1) Migration files on disk ---\n";
$migrationsPath = $laravelRoot.'/database/migrations';
foreach ($tosMigrations as $name) {
    $path = $migrationsPath.'/'.$name.'.php';
    $ok = is_file($path);
    echo '   '.$name.'.php: '.($ok ? 'OK' : 'MISSING')."\n";
    if (! $ok) {
        $failures[] = "Upload migration file: database/migrations/{$name}.php";
    }
}

echo "\n--- 2) migrations table rows ---\n";
try {
    foreach ($tosMigrations as $name) {
        $exists = Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', $name)
            ->exists();
        echo '   '.$name.': '.($exists ? 'RECORDED' : 'not recorded')."\n";
    }
} catch (Throwable $e) {
    $failures[] = 'Cannot read migrations table: '.$e->getMessage();
    echo '   FAILED: '.$e->getMessage()."\n";
}

echo "\n--- 3) Tables (before) ---\n";
$missingBefore = [];
foreach ($tosTables as $table) {
    $ok = Illuminate\Support\Facades\Schema::hasTable($table);
    echo '   '.$table.': '.($ok ? 'OK' : 'MISSING')."\n";
    if (! $ok) {
        $missingBefore[] = $table;
    }
}

echo "\n--- 4) Columns (before) ---\n";
foreach ($tosColumns as $table => $columns) {
    if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
        echo "   {$table}: (table missing)\n";
        continue;
    }
    foreach ($columns as $column) {
        $ok = Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        echo '   '.$table.'.'.$column.': '.($ok ? 'OK' : 'MISSING')."\n";
    }
}

if ($apply) {
    echo "\n--- 5) artisan migrate --force ---\n";
    try {
        // If tables are missing but migration is already recorded (orphan batch),
        // clear those rows so idempotent migrations can run again.
        if ($missingBefore !== []) {
            foreach ($tosMigrations as $name) {
                $deleted = Illuminate\Support\Facades\DB::table('migrations')
                    ->where('migration', $name)
                    ->delete();
                if ($deleted > 0) {
                    echo "   cleared orphan migration row: {$name}\n";
                }
            }
        }

        echo 'migrate --force... ';
        $kernel->call('migrate', ['--force' => true]);
        echo "OK\n";
        $out = trim($kernel->output());
        if ($out !== '') {
            echo $out."\n";
        }
    } catch (Throwable $e) {
        $failures[] = 'migrate failed: '.$e->getMessage();
        echo "FAILED: ".$e->getMessage()."\n\n";
        echo $e->getTraceAsString()."\n";
    }
} else {
    echo "\n--- 5) migrate skipped (scan only) ---\n";
    if ($missingBefore !== []) {
        echo "   Tables missing. Re-run with &apply=1 after confirming migration files are OK.\n";
    }
}

echo "\n--- 6) Tables (after) ---\n";
foreach ($tosTables as $table) {
    $ok = Illuminate\Support\Facades\Schema::hasTable($table);
    echo '   '.$table.': '.($ok ? 'OK' : 'MISSING')."\n";
    if (! $ok) {
        $failures[] = "Table still missing: {$table}";
    }
}

echo "\n--- 7) Columns (after) ---\n";
foreach ($tosColumns as $table => $columns) {
    if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
        continue;
    }
    foreach ($columns as $column) {
        $ok = Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        echo '   '.$table.'.'.$column.': '.($ok ? 'OK' : 'MISSING')."\n";
        if (! $ok) {
            $failures[] = "Column still missing: {$table}.{$column}";
        }
    }
}

echo "\n=== Summary ===\n";
if ($failures === []) {
    echo "Trading OS schema looks ready.\n";
    echo "Hard-refresh the SPA and retry Recommendations / Review / Notify log.\n";
    echo "\nDELETE cpanel-repair-tos-schema.php from public_html/portfolio/ now.\n";
    exit(0);
}

echo 'Completed with '.count($failures)." issue(s):\n";
foreach ($failures as $failure) {
    echo " - {$failure}\n";
}
echo "\nTypical fix: upload both 2026_07_25_*.php migration files to\n";
echo "  lidoportfolio/database/migrations/\n";
echo "then visit this script again with &apply=1.\n";
echo "DELETE this file after everything is green.\n";
exit(1);
