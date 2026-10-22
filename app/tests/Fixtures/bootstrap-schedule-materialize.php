<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing']);

$barrier = (string) getenv('BOOTSTRAP_SCHEDULE_BARRIER');
$worker = (string) ($argv[1] ?? '');
file_put_contents($barrier.'/'.$worker.'.ready', 'ready', LOCK_EX);
$deadline = microtime(true) + 15;
while (count(glob($barrier.'/*.ready') ?: []) < 2 && microtime(true) < $deadline) usleep(10000);
if (count(glob($barrier.'/*.ready') ?: []) < 2) {
    fwrite(STDERR, 'Timed out waiting for the second schedule tick.');
    exit(2);
}

$due = \Carbon\CarbonImmutable::parse((string) getenv('BOOTSTRAP_SCHEDULE_DUE_UTC'), 'UTC');
$scheduleId = filter_var(getenv('BOOTSTRAP_SCHEDULE_ID'), FILTER_VALIDATE_INT);
if (! $scheduleId) {
    fwrite(STDERR, 'A target schedule ID is required for the isolated materializer fixture.');
    exit(2);
}
echo app(\App\Services\Fundamentals\BootstrapScheduleOperationsService::class)->materializeDue($due, 100, (int) $scheduleId), PHP_EOL;
