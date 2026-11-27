<?php

// Private MySQL integration fixture: authenticated HTTP request with synthetic data only.
require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'api_failure_reporting.enabled' => false,
    'transactions.run_post_commit_side_effects_in_tests' => false,
]);

[$script, $runId, $worker, $barrierDirectory, $userId, $profileId, $token, $idempotencyKey, $encodedPayload] = $argv;
$personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
if (! $personalAccessToken || (int) $personalAccessToken->tokenable_id !== (int) $userId) {
    fwrite(STDERR, 'The isolated bearer token does not resolve to the expected synthetic user.');
    exit(2);
}
$app['auth']->resolveUsersUsing(fn ($guard = null) => $app['auth']->guard($guard)->user()
    ?? \App\Models\User::query()->find((int) $userId));
if (! preg_match('#^'.preg_quote(sys_get_temp_dir(), '#').'/stox_tx_req_barrier_[a-f0-9-]+$#', $barrierDirectory)) {
    fwrite(STDERR, 'Invalid isolated concurrency barrier path.');
    exit(2);
}
if (file_put_contents($barrierDirectory.'/ready-'.$worker, $runId, LOCK_EX) === false) {
    fwrite(STDERR, 'Unable to signal isolated concurrency barrier readiness.');
    exit(2);
}
$deadline = microtime(true) + 20;
do {
    if (is_file($barrierDirectory.'/released')) {
        break;
    }
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Timed out waiting for parent concurrency barrier.');
        exit(2);
    }
    usleep(20_000);
} while (true);
fwrite(STDERR, 'barrier-released worker='.$worker."\n");

$payload = json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
$request = \Illuminate\Http\Request::create(
    '/api/transactions', 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_X_PROFILE_ID' => (string) $profileId,
        'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR),
);
$response = $app->handle($request);
fwrite(STDERR, 'http-complete worker='.$worker.' status='.$response->getStatusCode()."\n");
echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
], JSON_THROW_ON_ERROR);
$app->terminate($request, $response);
