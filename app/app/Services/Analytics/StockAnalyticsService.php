<?php

namespace App\Services\Analytics;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\StockMetric;
use App\Services\RelativeStrengthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SD-031 — Stock Analytics owner.
 * Descriptive characteristics of a security (not Evaluation / Strategy scores).
 */
class StockAnalyticsService
{
    public function __construct(
        protected RelativeStrengthService $relativeStrength,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStock(Stock $stock, bool $useCache = true): array
    {
        if ($useCache) {
            $cached = DB::table('portfolio_stock_analytics_cache')
                ->where('stock_id', $stock->id)
                ->where('computed_at', '>=', now()->subHours(6))
                ->first();
            if ($cached) {
                $payload = json_decode($cached->payload_json, true);
                if (is_array($payload)) {
                    $payload['cached'] = true;

                    return $payload;
                }
            }
        }

        $payload = $this->compute($stock);
        $this->persistCache($stock->id, $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(Stock $stock): array
    {
        $bars = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->limit(260)
            ->get()
            ->sortBy('price_date')
            ->values();

        $closes = $bars->pluck('close')->map(fn ($c) => (float) $c)->all();
        $volumes = $bars->pluck('volume')->map(fn ($v) => $v !== null ? (float) $v : null)->all();
        $latest = $closes !== [] ? (float) end($closes) : null;

        $high52 = $bars->max('high');
        $low52 = $bars->min('low');
        $high52 = $high52 !== null ? (float) $high52 : null;
        $low52 = $low52 !== null ? (float) $low52 : null;

        $metrics = StockMetric::query()->where('stock_id', $stock->id)->first();
        $rs3m = $metrics?->relative_strength_3m;
        try {
            if ($rs3m === null) {
                $calc = $this->relativeStrength->calculateForStock($stock);
                $rs3m = $calc['relative_strength_3m'] ?? null;
            }
        } catch (\Throwable) {
            // keep null
        }

        $sma50 = $this->sma($closes, 50);
        $sma200 = $this->sma($closes, 200);
        $vol = $this->historicalVolatilityPct($closes, 20);
        $beta = $this->estimateBetaVsBenchmark($closes);
        $avgVol = $this->averageVolume($volumes, 20);
        $maxDd = $this->maxDrawdownPct($closes);
        $curDd = $this->currentDrawdownPct($closes);

        $distHigh = ($latest !== null && $high52 && $high52 > 0)
            ? round((($latest - $high52) / $high52) * 100, 2) : null;
        $distLow = ($latest !== null && $low52 && $low52 > 0)
            ? round((($latest - $low52) / $low52) * 100, 2) : null;

        $trendStrength = null;
        if ($latest !== null && $sma50 !== null && $sma200 !== null) {
            $trendStrength = round(
                (($latest > $sma50 ? 40 : 0) + ($sma50 > $sma200 ? 40 : 0) + ($latest > $sma200 ? 20 : 0)),
                2
            );
        }

        $liquidity = $this->liquidityRating($avgVol, $latest);

        return [
            'owner' => 'stock_analytics',
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'latest_close' => $latest,
            'beta' => $beta,
            'historical_volatility_pct' => $vol,
            'relative_strength' => $rs3m !== null ? round((float) $rs3m, 2) : null,
            'trend_strength' => $trendStrength,
            'maximum_drawdown_pct' => $maxDd,
            'current_drawdown_pct' => $curDd,
            'distance_52w_high_pct' => $distHigh,
            'distance_52w_low_pct' => $distLow,
            'high_52w' => $high52,
            'low_52w' => $low52,
            'average_daily_volume' => $avgVol,
            'liquidity_rating' => $liquidity,
            'sma_50' => $sma50,
            'sma_200' => $sma200,
            'computed_at' => now()->toIso8601String(),
            'cached' => false,
        ];
    }

    protected function persistCache(int $stockId, array $payload): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_stock_analytics_cache')) {
            return;
        }
        DB::table('portfolio_stock_analytics_cache')->updateOrInsert(
            ['stock_id' => $stockId],
            [
                'payload_json' => json_encode($payload),
                'computed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param  list<float>  $closes
     */
    protected function sma(array $closes, int $period): ?float
    {
        if (count($closes) < $period) {
            return null;
        }
        $slice = array_slice($closes, -$period);

        return round(array_sum($slice) / $period, 4);
    }

    /**
     * @param  list<float>  $closes
     */
    protected function historicalVolatilityPct(array $closes, int $window = 20): ?float
    {
        if (count($closes) < $window + 1) {
            return null;
        }
        $rets = [];
        $slice = array_slice($closes, -($window + 1));
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i - 1] > 0) {
                $rets[] = log($slice[$i] / $slice[$i - 1]);
            }
        }
        if ($rets === []) {
            return null;
        }
        $mean = array_sum($rets) / count($rets);
        $var = 0.0;
        foreach ($rets as $r) {
            $var += ($r - $mean) ** 2;
        }
        $std = sqrt($var / max(1, count($rets) - 1));

        return round($std * sqrt(252) * 100, 2);
    }

    /**
     * Simplified beta vs equal-weight synthetic (uses stock's own variance proxy when benchmark unavailable).
     *
     * @param  list<float>  $closes
     */
    protected function estimateBetaVsBenchmark(array $closes): ?float
    {
        $vol = $this->historicalVolatilityPct($closes, 60);
        if ($vol === null) {
            return null;
        }
        // Map annualised vol to a soft beta proxy (market ~15–18% vol → beta ~1).
        return round(max(0.2, min(2.5, $vol / 16.0)), 2);
    }

    /**
     * @param  list<float|null>  $volumes
     */
    protected function averageVolume(array $volumes, int $window = 20): ?float
    {
        $nums = array_values(array_filter($volumes, fn ($v) => $v !== null && $v > 0));
        if (count($nums) < 5) {
            return null;
        }
        $slice = array_slice($nums, -$window);

        return round(array_sum($slice) / count($slice), 0);
    }

    /**
     * @param  list<float>  $closes
     */
    protected function maxDrawdownPct(array $closes): ?float
    {
        if (count($closes) < 2) {
            return null;
        }
        $peak = $closes[0];
        $maxDd = 0.0;
        foreach ($closes as $c) {
            if ($c > $peak) {
                $peak = $c;
            }
            if ($peak > 0) {
                $dd = (($c - $peak) / $peak) * 100;
                if ($dd < $maxDd) {
                    $maxDd = $dd;
                }
            }
        }

        return round($maxDd, 2);
    }

    /**
     * @param  list<float>  $closes
     */
    protected function currentDrawdownPct(array $closes): ?float
    {
        if ($closes === []) {
            return null;
        }
        $peak = max($closes);
        $latest = (float) end($closes);
        if ($peak <= 0) {
            return null;
        }

        return round((($latest - $peak) / $peak) * 100, 2);
    }

    protected function liquidityRating(?float $avgVol, ?float $price): string
    {
        if ($avgVol === null || $price === null) {
            return 'Unknown';
        }
        $notional = $avgVol * $price;
        if ($notional >= 50_000_000) {
            return 'High';
        }
        if ($notional >= 5_000_000) {
            return 'Medium';
        }

        return 'Low';
    }
}
