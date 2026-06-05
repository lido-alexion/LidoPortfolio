<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$presentation = app(App\Services\HoldingPresentationService::class);
$user = App\Models\User::query()->orderByDesc('id')->first();

if (! $user) {
    echo "No users\n";
    exit(1);
}

echo "User: {$user->email}\n\n";

$holdings = $user->holdings()->with('stock')->where('quantity', '>', 0)->get();

foreach ($holdings as $holding) {
    $stock = $holding->stock;
    $firstBuy = $presentation->firstBuyDateForCurrentPosition($user, $stock);
    $totalPrices = App\Models\StockPrice::query()->where('stock_id', $stock->id)->count();
    $sinceBuy = $firstBuy
        ? App\Models\StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $firstBuy->toDateString())
            ->count()
        : 0;

    echo "Stock: {$stock->symbol} (id={$stock->id})\n";
    echo "  first_buy_date: ".($firstBuy?->toDateString() ?? 'null')."\n";
    echo "  total price rows in DB: {$totalPrices}\n";
    echo "  price rows since buy: {$sinceBuy}\n";

    $logs = App\Models\SystemLog::query()
        ->where('message', 'like', '%'.$stock->symbol.'%')
        ->orderByDesc('id')
        ->limit(3)
        ->get();

    foreach ($logs as $log) {
        echo "  log [{$log->log_type}]: {$log->message}\n";
    }
    echo "\n";
}
