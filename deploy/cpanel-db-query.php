<?php
/**
 * Read-only SQL against the app database (no SSH). DELETE before public launch.
 *
 * Upload: public_html/portfolio/cpanel-db-query.php
 * POST JSON: { "token": "Lido", "query": "SELECT ...", "limit": 200 }
 * Or form: token, query, limit
 *
 * Allowed: SELECT, SHOW, DESCRIBE, DESC, EXPLAIN (single statement only).
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';
const DEFAULT_ROW_LIMIT = 200;
const MAX_ROW_LIMIT = 1000;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    exit($status >= 400 ? 1 : 0);
}

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
    respond(500, ['ok' => false, 'error' => 'Laravel root not found']);
}

$rawBody = file_get_contents('php://input') ?: '';
$json = json_decode($rawBody, true);
$input = is_array($json) ? $json : $_POST;

$token = (string) ($input['token'] ?? $_GET['token'] ?? '');
if ($token !== SETUP_TOKEN) {
    respond(403, ['ok' => false, 'error' => 'Forbidden — set SETUP_TOKEN in file and pass token']);
}

$query = trim((string) ($input['query'] ?? ''));
if ($query === '') {
    respond(400, [
        'ok' => false,
        'error' => 'Missing query',
        'example' => [
            'token' => 'Lido',
            'query' => 'SELECT setting_key, setting_value FROM portfolio_settings WHERE setting_key LIKE \'universe%\'',
            'limit' => 50,
        ],
    ]);
}

function isReadOnlySqlAllowed(string $sql): bool
{
    $sql = trim($sql);
    if ($sql === '') {
        return false;
    }

    $normalized = rtrim($sql, " \t\n\r\0\x0B;");
    if (str_contains($normalized, ';')) {
        return false;
    }

    $upper = strtoupper(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

    $allowed = false;
    foreach (['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'] as $prefix) {
        if (str_starts_with($upper, $prefix.' ') || $upper === $prefix) {
            $allowed = true;
            break;
        }
    }

    if (! $allowed) {
        return false;
    }

    foreach (['INTO OUTFILE', 'INTO DUMPFILE', 'FOR UPDATE', 'LOCK IN SHARE MODE', 'LOAD_FILE'] as $forbidden) {
        if (str_contains($upper, $forbidden)) {
            return false;
        }
    }

    return true;
}

require $laravelRoot.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (! isReadOnlySqlAllowed($query)) {
    respond(400, [
        'ok' => false,
        'error' => 'Only single-statement read-only SQL is allowed (SELECT, SHOW, DESCRIBE, EXPLAIN)',
        'query' => $query,
    ]);
}

$limit = DEFAULT_ROW_LIMIT;
if (isset($input['limit']) && is_numeric($input['limit'])) {
    $limit = max(1, min(MAX_ROW_LIMIT, (int) $input['limit']));
}

$started = microtime(true);

try {
    $rows = DB::select($query);
    $truncated = false;
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
        $truncated = true;
    }

    $normalized = array_map(static function ($row) {
        return (array) $row;
    }, $rows);

    respond(200, [
        'ok' => true,
        'row_count' => count($normalized),
        'truncated' => $truncated,
        'limit' => $limit,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'query' => $query,
        'rows' => $normalized,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'ok' => false,
        'error' => $e->getMessage(),
        'query' => $query,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    ]);
}
