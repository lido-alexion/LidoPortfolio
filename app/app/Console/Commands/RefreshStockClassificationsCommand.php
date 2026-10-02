<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockClassificationObservation;
use App\Models\StockClassificationRefreshState;
use App\Services\StockClassificationService;
use Illuminate\Console\Command;

class RefreshStockClassificationsCommand extends Command
{
    protected $signature = 'stox:refresh-stock-classifications {--batch=50}';

    protected $description = 'Refresh free NSE sector/industry observations for due and unknown stocks';

    public function handle(StockClassificationService $classifications): int
    {
        $batch = max(1, min((int) $this->option('batch'), 200));
        $stocks = Stock::query()
            ->where('is_active', true)
            ->where('is_benchmark', false)
            ->where('exchange', 'NSE')
            ->select('portfolio_stocks.*')
            ->where(function ($query): void {
                $query->whereNotExists(function ($state): void {
                    $state->selectRaw('1')->from('stox_stock_classification_refresh_states')
                        ->whereColumn('stock_id', 'portfolio_stocks.id')
                        ->where('provider', StockClassificationService::PROVIDER)
                        ->where('taxonomy_version', StockClassificationService::TAXONOMY);
                })->orWhereExists(function ($state): void {
                    $state->selectRaw('1')->from('stox_stock_classification_refresh_states')
                        ->whereColumn('stock_id', 'portfolio_stocks.id')
                        ->where('provider', StockClassificationService::PROVIDER)
                        ->where('taxonomy_version', StockClassificationService::TAXONOMY)
                        ->where(function ($due): void {
                            $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                        });
                });
            })
            ->selectSub(
                StockClassificationObservation::query()
                    ->selectRaw('MAX(observed_at)')
                    ->whereColumn('stock_id', 'portfolio_stocks.id')
                    ->where('provider', StockClassificationService::PROVIDER),
                'classification_last_observed_at',
            )
            ->orderByRaw('classification_last_observed_at IS NOT NULL')
            ->orderBy('classification_last_observed_at')
            ->orderBy('created_at')
            ->limit($batch)
            ->get();

        $succeeded = 0;
        $failed = 0;
        foreach ($stocks as $stock) {
            try {
                $classifications->refresh($stock);
                $succeeded++;
            } catch (\Throwable $error) {
                $failed++;
                $this->warn($stock->symbol.': '.$error->getMessage());
            }
        }

        $this->info("Classification refresh: selected={$stocks->count()} succeeded={$succeeded} failed={$failed}");
        return $failed > 0 && $succeeded === 0 ? self::FAILURE : self::SUCCESS;
    }
}
