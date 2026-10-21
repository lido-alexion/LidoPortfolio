<?php

// Child-process MySQL lock probe for the STR14 lifecycle feature tests only.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mode = (string) ($argv[1] ?? '');
$profileId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
$strategyId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
$recommendationId = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
$stockId = filter_var($argv[5] ?? null, FILTER_VALIDATE_INT);
if (! in_array($mode, ['archive', 'capital', 'settlement'], true)
    || ! $profileId || ! $strategyId || ! $recommendationId || ! $stockId) {
    fwrite(STDERR, "invalid test fixture arguments\n");
    exit(23);
}

\Illuminate\Support\Facades\DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');

try {
    $profile = \App\Models\PortfolioProfile::query()->findOrFail($profileId);
    $strategy = \App\Models\TradingStrategy::query()->findOrFail($strategyId);
    $recommendation = \App\Models\TradingRecommendation::query()->findOrFail($recommendationId);

    if ($mode === 'archive') {
        app(\App\Services\Artifacts\StrategyArtifactRegistry::class)
            ->archive((string) $strategyId, $profile, 'Retirement requested');
    } elseif ($mode === 'capital') {
        app(\App\Services\Lending\CapitalRequestService::class)
            ->createRequest($profile, $recommendation, $strategy, 5000.0);
    } else {
        app(\App\Services\Lending\SaleProceedsAvailabilityService::class)
            ->scheduleForObligation($profile, $strategy, 125.0, 125.0, \App\Models\PendingSaleProceeds::OBLIGATION_RECALL);
    }

    fwrite(STDOUT, "unexpected-success\n");
    exit(21);
} catch (\Illuminate\Database\QueryException $error) {
    $errorInfo = $error->errorInfo ?? [];
    $sqlstate = (string) ($errorInfo[0] ?? $error->getCode());
    $errno = (int) ($errorInfo[1] ?? 0);
    if ($sqlstate === 'HY000' && $errno === 1205) {
        fwrite(STDOUT, '{"status":"lock-timeout","sqlstate":"HY000","errno":1205}'.PHP_EOL);
        exit(20);
    }

    fwrite(STDERR, json_encode(['status' => 'unexpected-query-error', 'sqlstate' => $sqlstate, 'errno' => $errno], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(22);
} catch (\Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(22);
}
