<?php

declare(strict_types=1);

define('REPAIR_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/repair-ml-acceptance-private-permissions.php';

$queueConfig = ['ml-acceptance' => [
    'driver' => 'database', 'connection' => 'queue_archive', 'table' => 'portfolio_jobs', 'queue' => 'ml-acceptance',
]];
$databaseConnections = ['queue_archive' => ['driver' => 'mysql', 'database' => 'isolated_queue_db']];
$targets = [];
$queueRows = 1;
$mutations = 0;
$countJobs = static function (string $connection, string $table, string $queue) use (&$targets, &$queueRows): int {
    $targets[] = [$connection, $table, $queue];
    return $queueRows;
};

foreach (['initial safety check', 'pre-apply safety check'] as $phase) {
    try {
        assertAcceptanceQueueEmpty($queueConfig, 'application_db', $databaseConnections, $countJobs, $phase);
        $mutations++; // Represents the first operation after either guard.
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'dedicated acceptance queue is not empty')) throw $error;
    }
}

if ($mutations !== 0) throw new RuntimeException('a repair mutation ran despite the nonempty queue');
if ($targets !== array_fill(0, 2, ['queue_archive', 'portfolio_jobs', 'ml-acceptance'])) {
    throw new RuntimeException('guard did not query the configured connection/table/queue at both checks');
}

$queueRows = 0;
assertAcceptanceQueueEmpty($queueConfig, 'application_db', $databaseConnections, $countJobs, 'empty queue control');

foreach ([
    ['driver' => 'sync', 'connection' => 'queue_archive', 'table' => 'portfolio_jobs', 'queue' => 'ml-acceptance'],
    ['driver' => 'database', 'connection' => 'queue_archive', 'table' => 'jobs;drop', 'queue' => 'ml-acceptance'],
    ['driver' => 'database', 'connection' => 'missing', 'table' => 'portfolio_jobs', 'queue' => 'ml-acceptance'],
] as $invalid) {
    try {
        acceptanceQueueTarget(['ml-acceptance' => $invalid], 'application_db', $databaseConnections);
        throw new RuntimeException('invalid queue configuration was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'invalid queue configuration was accepted') throw $error;
    }
}

echo "Configured acceptance queue guard behavior passed.\n";
