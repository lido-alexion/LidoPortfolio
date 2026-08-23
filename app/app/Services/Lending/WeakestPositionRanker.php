<?php

namespace App\Services\Lending;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Services\StockQuoteService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * OD-16 weakest-position ranking for recall liquidation (§17 / DEP-WEAKEST-PRICE/HISTORY).
 * Holding-period / evaluation window default = 3 months (90 calendar days).
 */
final class WeakestPositionRanker
{
    public const DEFAULT_WINDOW_DAYS = 90; // 3 months

    public function __construct(
        protected StockQuoteService $quotes,
    ) {}

    public function evaluationWindowDays(TradingStrategy $strategy): int
    {
        $version = $strategy->activeVersion
            ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
        $config = is_array($version?->config_json) ? $version->config_json : [];
        $raw = $config['weakest_position_window_days']
            ?? $config['holding_period_days']
            ?? ($config['portfolio_rules']['holding_period_days'] ?? null);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_WINDOW_DAYS;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : self::DEFAULT_WINDOW_DAYS;
    }

    /**
     * @return list<array{
     *   holding: Holding,
     *   window_return_pct: float,
     *   market_value: float,
     *   current_price: float,
     *   age_days: int,
     *   age_capped_days: int
     * }>
     */
    public function rankBorrowerPositions(
        PortfolioProfile $profile,
        TradingStrategy $borrower,
        ?CarbonInterface $asOf = null,
    ): array {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $windowDays = $this->evaluationWindowDays($borrower);

        $holdings = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('strategy_id', $borrower->id)
            ->where('quantity', '>', 0)
            ->orderBy('stock_id')
            ->get();

        $ranked = [];
        foreach ($holdings as $holding) {
            $price = $this->quotes->latestClose((int) $holding->stock_id, $asOf);
            if ($price <= 0) {
                continue;
            }
            $mv = round((float) $holding->quantity * $price, 4);
            if ($mv <= 0) {
                continue;
            }

            $ageDays = $this->holdingAgeCalendarDays($profile, $holding, $asOf);
            $ageCapped = min($ageDays, $windowDays);
            $windowReturn = $this->windowReturnPct((int) $holding->stock_id, $windowDays, $asOf, $price);

            $ranked[] = [
                'holding' => $holding,
                'window_return_pct' => $windowReturn,
                'market_value' => $mv,
                'current_price' => $price,
                'age_days' => $ageDays,
                'age_capped_days' => $ageCapped,
            ];
        }

        usort($ranked, function (array $a, array $b): int {
            $cmp = $a['window_return_pct'] <=> $b['window_return_pct'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int) $a['holding']->stock_id <=> (int) $b['holding']->stock_id;
        });

        return $ranked;
    }

    public function windowReturnPct(
        int $stockId,
        int $windowDays,
        CarbonInterface $asOf,
        ?float $currentPrice = null,
    ): float {
        $asOf = Carbon::parse($asOf);
        $current = $currentPrice ?? $this->quotes->latestClose($stockId, $asOf);
        if ($current <= 0) {
            return 0.0;
        }

        $windowStartDate = $asOf->copy()->startOfDay()->subDays($windowDays);
        $startPrice = $this->closeOnOrBefore($stockId, $windowStartDate);
        if ($startPrice === null || $startPrice <= 0) {
            // DEP-WEAKEST-HISTORY: shorten to earliest available
            $startPrice = $this->earliestClose($stockId, $asOf);
        }
        if ($startPrice === null || $startPrice <= 0) {
            return 0.0;
        }

        return round((($current - $startPrice) / $startPrice) * 100.0, 6);
    }

    public function holdingAgeCalendarDays(
        PortfolioProfile $profile,
        Holding $holding,
        CarbonInterface $asOf,
    ): int {
        $firstBuy = Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $holding->stock_id)
            ->where('type', 'buy')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->value('transaction_date');

        if ($firstBuy === null) {
            return 0;
        }

        return Carbon::parse($firstBuy)->startOfDay()->diffInDays(Carbon::parse($asOf)->startOfDay());
    }

    protected function closeOnOrBefore(int $stockId, CarbonInterface $onOrBefore): ?float
    {
        $close = StockPrice::query()
            ->where('stock_id', $stockId)
            ->where('price_date', '<=', Carbon::parse($onOrBefore)->toDateString())
            ->orderByDesc('price_date')
            ->value('close_price');

        return $close !== null ? (float) $close : null;
    }

    protected function earliestClose(int $stockId, CarbonInterface $asOf): ?float
    {
        $close = StockPrice::query()
            ->where('stock_id', $stockId)
            ->where('price_date', '<=', Carbon::parse($asOf)->toDateString())
            ->orderBy('price_date')
            ->value('close_price');

        return $close !== null ? (float) $close : null;
    }
}
