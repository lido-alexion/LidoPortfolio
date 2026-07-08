<?php
/**
 * Read Laravel log files (no SSH). DELETE before public launch.
 *
 * Upload: public_html/portfolio/cpanel-read-logs.php
 *
 * List files:  ?token=Lido
 * Read tail:   ?token=Lido&file=scheduler&tail=300
 * Grep:        ?token=Lido&file=scheduler&grep=universe_maintenance&tail=500
 *
 * file= scheduler | laravel | provider | frontend | all (lists only)
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';
const DEFAULT_TAIL = 200;
const MAX_TAIL = 5000;
const MAX_FILE_BYTES = 2_000_000;

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN, then visit ?token=YOUR_TOKEN\n");
}

header('Content-Type: application/json; charset=utf-8');

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
    echo json_encode(['ok' => false, 'error' => 'Laravel root not found'], JSON_PRETTY_PRINT);
    exit(1);
}

$logsDir = $laravelRoot.'/storage/logs';
$fileKey = strtolower(trim((string) ($_GET['file'] ?? '')));
$tail = DEFAULT_TAIL;
if (isset($_GET['tail']) && is_numeric($_GET['tail'])) {
    $tail = max(1, min(MAX_TAIL, (int) $_GET['tail']));
}
$grep = trim((string) ($_GET['grep'] ?? ''));

$allowedPrefixes = [
    'laravel' => 'laravel-',
    'scheduler' => 'scheduler-',
    'provider' => 'provider-',
    'frontend' => 'frontend-',
];

function listLogFiles(string $logsDir, array $allowedPrefixes): array
{
    if (! is_dir($logsDir)) {
        return [];
    }

    $out = [];
    foreach (scandir($logsDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $logsDir.'/'.$name;
        if (! is_file($path)) {
            continue;
        }
        $category = 'other';
        foreach ($allowedPrefixes as $key => $prefix) {
            if (str_starts_with($name, $prefix) && str_ends_with($name, '.log')) {
                $category = $key;
                break;
            }
        }
        $out[] = [
            'name' => $name,
            'category' => $category,
            'bytes' => filesize($path) ?: 0,
            'modified_at' => date('c', filemtime($path) ?: time()),
        ];
    }

    usort($out, static fn ($a, $b) => strcmp($b['modified_at'], $a['modified_at']));

    return $out;
}

function tailFile(string $path, int $lines): array
{
    if (! is_file($path)) {
        return [];
    }
    if (filesize($path) > MAX_FILE_BYTES) {
        $chunk = file_get_contents($path, false, null, max(0, filesize($path) - MAX_FILE_BYTES));
        $content = $chunk !== false ? $chunk : '';
    } else {
        $content = (string) file_get_contents($path);
    }

    $allLines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    if (count($allLines) > $lines) {
        $allLines = array_slice($allLines, -$lines);
    }

    return $allLines;
}

if ($fileKey === '' || $fileKey === 'all') {
    echo json_encode([
        'ok' => true,
        'logs_dir' => $logsDir,
        'files' => listLogFiles($logsDir, $allowedPrefixes),
        'usage' => [
            'read' => '?token=...&file=scheduler&tail=300',
            'grep' => '?token=...&file=scheduler&grep=heartbeat&tail=500',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if (! isset($allowedPrefixes[$fileKey])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid file key',
        'allowed' => array_keys($allowedPrefixes),
    ], JSON_PRETTY_PRINT);
    exit(1);
}

$prefix = $allowedPrefixes[$fileKey];
$files = listLogFiles($logsDir, $allowedPrefixes);
$target = null;
foreach ($files as $meta) {
    if ($meta['category'] === $fileKey) {
        $target = $meta['name'];
        break;
    }
}

if ($target === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => "No log file found for category {$fileKey}",
        'files' => $files,
    ], JSON_PRETTY_PRINT);
    exit(1);
}

$path = $logsDir.'/'.$target;
$lines = tailFile($path, $tail);

if ($grep !== '') {
    $lines = array_values(array_filter($lines, static fn ($line) => stripos($line, $grep) !== false));
}

echo json_encode([
    'ok' => true,
    'file' => $target,
    'category' => $fileKey,
    'path' => $path,
    'tail_requested' => $tail,
    'grep' => $grep !== '' ? $grep : null,
    'line_count' => count($lines),
    'lines' => $lines,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
