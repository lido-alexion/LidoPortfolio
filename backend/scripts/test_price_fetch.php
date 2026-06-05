<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$symbol = $argv[1] ?? null;
$stock = $symbol
    ? App\Models\Stock::query()->where('symbol', strtoupper($symbol))->first()
    : App\Models\Stock::query()->where('is_benchmark', false)->orderByDesc('id')->first();

if (! $stock) {
    echo "No stock found\n";
    exit(1);
}

$from = Carbon\Carbon::now()->subDays(10);
$to = Carbon\Carbon::now();
$svc = app(App\Services\PriceFetchService::class);

echo "Testing {$stock->symbol} from {$from->toDateString()} to {$to->toDateString()}\n";
$sync = $svc->syncStock($stock, $from, $to);
echo "Stored rows: {$sync['stored_rows']} provider={$sync['provider']} success=".($sync['success'] ? 'yes' : 'no')."\n";
if (! $sync['success']) {
    echo "Errors:\n  - ".implode("\n  - ", $sync['errors'])."\n";
}

$logs = App\Models\SystemLog::query()->orderByDesc('id')->limit(5)->get();
foreach ($logs as $log) {
    echo "[{$log->log_type}] {$log->message}\n";
}
