<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing']);

$mode = (string) ($argv[1] ?? '');
$id = (int) ($argv[2] ?? 0);
$readyPath = (string) getenv('CA_REPAIR_READY_PATH');
$resultPath = (string) getenv('CA_REPAIR_RESULT_PATH');
if ($mode === 'accept') {
    $staleIssue = \App\Models\DataQualityIssue::query()->findOrFail($id);
    file_put_contents($readyPath, 'stale-issue-loaded', LOCK_EX);
    try {
        app(\App\Services\DataQualityResolutionService::class)->accept($staleIssue, 2.0, 'Competing stale acceptance.', null);
        $workerResult = ['accepted' => true];
    } catch (\App\Exceptions\DomainException $exception) {
        $workerResult = ['exception_code' => $exception->errorCode(), 'http_status' => $exception->httpStatus()];
    }
    file_put_contents($resultPath, json_encode($workerResult, JSON_THROW_ON_ERROR), LOCK_EX);
    exit(0);
}
if ($mode === 'rollback') {
    $issue = \App\Models\DataQualityIssue::query()->findOrFail($id);
    file_put_contents($readyPath, 'rollback-issue-loaded', LOCK_EX);
    try {
        app(\App\Services\DataQualityResolutionService::class)->rollbackCompletedRepair($issue, 'Competing rollback worker.', null);
        $workerResult = ['rolled_back' => true];
    } catch (\App\Exceptions\DomainException $exception) {
        $workerResult = ['exception_code' => $exception->errorCode(), 'http_status' => $exception->httpStatus()];
    }
    file_put_contents($resultPath, json_encode($workerResult, JSON_THROW_ON_ERROR), LOCK_EX);
    exit(0);
}
$table = match ($mode) {
    'action' => 'portfolio_corporate_actions',
    'factor' => 'portfolio_price_adjustment_factors',
    default => throw new RuntimeException('Unsupported repair concurrency mode.'),
};

// The ready signal fires after the repair's parent-key SELECT has completed.
// The parent test holds the stock lock and commits a competing writer only
// after this signal, so the worker must re-read application state after locking.
DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use ($table, $readyPath): void {
    $sql = strtolower(preg_replace('/\s+/', ' ', trim($query->sql)));
    // Eloquent may qualify stock_id and the factor lookup selects stock_id
    // alongside issue_id. Require stock_id in the SELECT list and the exact
    // parent table so unrelated queries cannot release the race.
    $parentTable = preg_quote($table, '/');
    if (preg_match('/^select\s+(.*?)\s+from\s+[`"]?'.$parentTable.'[`"]?(?:\s|$)/', $sql, $matches) === 1
        && preg_match('/(?:^|[,.\s])stock_id(?:$|[,.\s])/i', str_replace(['`', '"'], '', $matches[1])) === 1) {
        file_put_contents($readyPath, 'parent-key-read', LOCK_EX);
    }
});

$metrics = \Mockery::mock(\App\Services\MetricsUpdateService::class);
$metrics->shouldReceive('updateStock')->andReturnUsing(static function (\App\Models\Stock $stock) {
    return \App\Models\StockMetric::query()->firstOrCreate(
        ['stock_id' => $stock->id],
        ['highest_close' => 0, 'latest_close' => 0, 'updated_at' => now()],
    );
});
$app->instance(\App\Services\MetricsUpdateService::class, $metrics);

$service = app(\App\Services\CorporateActionPriceRepairService::class);
$result = $mode === 'action'
    ? $service->repair(actionId: $id, dryRun: false)
    : $service->repairPendingFactors(factorId: $id, dryRun: false);

file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
