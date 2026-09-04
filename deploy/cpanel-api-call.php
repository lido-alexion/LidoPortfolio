<?php
/**
 * Call any Laravel API route with the debug agent token (no browser session). DELETE before launch.
 *
 * Upload: public_html/portfolio/cpanel-api-call.php
 *
 * GET/POST params:
 *   token=Lido
 *   path=api/universe-price-sync/status   (with or without leading /)
 *   method=GET|POST|PUT|DELETE            (default GET)
 *   body={"scope":"all_equities"}         (JSON string for POST/PUT)
 *   portfolio_id=1                        (optional X-Profile-Id header)
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if ($token !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN, then pass token=...\n");
}

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
    header('Content-Type: text/plain; charset=utf-8');
    exit("Laravel root not found.\n");
}

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$path = trim((string) ($_GET['path'] ?? $_POST['path'] ?? 'api/auth/me'), '/');
if (! str_starts_with($path, 'api/')) {
    $path = 'api/'.$path;
}

$method = strtoupper(trim((string) ($_GET['method'] ?? $_POST['method'] ?? 'GET')));
if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit(1);
}

$bodyRaw = (string) ($_GET['body'] ?? $_POST['body'] ?? '');
$bodyParams = [];
if ($bodyRaw !== '') {
    $decoded = json_decode($bodyRaw, true);
    if (! is_array($decoded)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'body must be valid JSON object']);
        exit(1);
    }
    $bodyParams = $decoded;
}

$portfolioId = $_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? null;
$host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

$server = [
    'HTTP_HOST' => $host,
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_LIDO_DEBUG_TOKEN' => SETUP_TOKEN,
    'REQUEST_METHOD' => $method,
    'HTTPS' => 'on',
];

if ($portfolioId !== null && $portfolioId !== '') {
    $server['HTTP_X_PROFILE_ID'] = (string) $portfolioId;
}

$content = $method === 'GET' ? '' : json_encode($bodyParams);
if ($content !== '') {
    $server['CONTENT_TYPE'] = 'application/json';
}

$request = Illuminate\Http\Request::create('/'.$path, $method, $method === 'GET' ? $bodyParams : [], [], [], $server, $content);

$response = $app->handle($request);
$body = (string) $response->getContent();
$isJson = str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
    'status' => $response->getStatusCode(),
    'path' => '/'.$path,
    'method' => $method,
    'content_type' => $response->headers->get('Content-Type'),
    'request_id' => $response->headers->get('X-Request-ID'),
    'body' => $isJson ? json_decode($body, true) : $body,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
