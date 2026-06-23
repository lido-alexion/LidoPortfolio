<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\User;
use Carbon\Carbon;

class StoplossService
{
    public function __construct(
        protected SettingsService $settings,
        protected HoldingPresentationService $holdingPresentation,
    ) {}

    public function updateMetricsForStock(Stock $stock): StockMetric
    {
        $metric = StockMetric::query()->firstOrCreate(
            ['stock_id' => $stock->id],
            [
                'stoploss_percent' => (float) $this->settings->get('default_stoploss_percent', '10'),
                'tracking_active' => true,
                'updated_at' => now(),
            ],
        );

        if (! $metric->tracking_active) {
            return $metric;
        }

        $hasActiveHolding = Holding::query()
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->exists();

        if (! $hasActiveHolding && ! $stock->is_benchmark) {
            $metric->update(['tracking_active' => false, 'updated_at' => now()]);

            return $metric->fresh();
        }

        if ($stock->is_benchmark) {
            return $metric;
        }

        $latestPrice = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->first();

        if (! $latestPrice) {
            return $metric;
        }

        $latestClose = (float) $latestPrice->close_price;
        $peakClose = (float) (StockPrice::query()
            ->where('stock_id', $stock->id)
            ->max('close_price') ?? 0);
        $highestClose = max((float) ($metric->highest_close ?? 0), $latestClose, $peakClose);
        $stopPercent = (float) $metric->stoploss_percent;
        $trailingStop = $highestClose * (1 - ($stopPercent / 100));

        $metric->update([
            'latest_close' => $latestClose,
            'highest_close' => $highestClose,
            'trailing_stop_price' => round($trailingStop, 4),
            'updated_at' => now(),
        ]);

        if ($latestClose <= $trailingStop) {
            $this->triggerStoplossAlert($stock, $latestClose);
        }

        return $metric->fresh();
    }

    public function processAllActiveStocks(): void
    {
        $stockIds = Holding::query()
            ->where('quantity', '>', 0)
            ->distinct()
            ->pluck('stock_id');

        foreach ($stockIds as $stockId) {
            $stock = Stock::query()->find($stockId);
            if ($stock) {
                $this->updateMetricsForStock($stock);
            }
        }
    }

    protected function triggerStoplossAlert(Stock $stock, float $latestClose): void
    {
        $holdings = Holding::query()
            ->with('stock')
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->get();

        if ($holdings->isEmpty()) {
            return;
        }

        foreach ($holdings as $holding) {
            $user = User::query()->find($holding->user_id);
            if (! $user) {
                continue;
            }

            $exists = Alert::query()
                ->where('user_id', $user->id)
                ->where('stock_id', $stock->id)
                ->where('alert_type', 'stoploss_triggered')
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($exists) {
                continue;
            }

            $summary = $this->holdingPresentation->enrichHolding($user, $holding)['stoploss_summary'] ?? [];
            $message = $this->buildStoplossAlertMessage($stock, $summary, $latestClose);

            Alert::query()->create([
                'user_id' => $user->id,
                'stock_id' => $stock->id,
                'alert_type' => 'stoploss_triggered',
                'message' => $message,
                'is_sent' => false,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildStoplossAlertMessage(Stock $stock, array $summary, float $latestClose): string
    {
        $stopPercent = $summary['stoploss_percent'] ?? null;
        $highest = $summary['highest_close_since_buy'] ?? null;
        $highestDate = $summary['highest_close_since_buy_date'] ?? null;
        $latestDate = $summary['latest_price_date'] ?? null;
        $trailing = $summary['trailing_stop_price'] ?? null;
        $displayLatest = $summary['latest_close'] ?? $latestClose;

        $latestPart = sprintf('Latest close: %.2f', (float) $displayLatest);
        if ($latestDate) {
            $latestPart .= ' ('.$this->formatAlertDate((string) $latestDate).')';
        }

        $trailingDetail = '';
        if ($stopPercent !== null && $highest !== null && (float) $highest > 0) {
            $trailingDetail = sprintf(
                ' (%s%% below highest close %.2f%s)',
                $this->formatStopPercent((float) $stopPercent),
                (float) $highest,
                $highestDate ? ' on '.$this->formatAlertDate((string) $highestDate) : ''
            );
        }

        return sprintf(
            'Stoploss triggered for %s (%s). %s. Trailing stop: %.2f%s',
            $stock->name,
            $stock->symbol,
            $latestPart,
            (float) ($trailing ?? 0),
            $trailingDetail
        );
    }

    protected function formatAlertDate(string $date): string
    {
        return Carbon::parse($date)->format('d-M-Y');
    }

    protected function formatStopPercent(float $percent): string
    {
        $rounded = round($percent, 2);

        return abs($rounded - round($rounded)) < 0.001
            ? (string) (int) round($rounded)
            : rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    public function getActiveAlertsForUser(User $user): array
    {
        return Alert::query()
            ->active()
            ->with('stock')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->toArray();
    }
}
