<?php

namespace App\Services\AI;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\V7\FundamentalFact;
use App\Services\Fundamentals\AI\FundamentalInsightReuse;
use App\Services\Fundamentals\FundamentalInvestorSnapshotService;
use App\Services\Fundamentals\FundamentalSignalsService;
use App\Services\IndexCatalogService;
use App\Services\PatternDetectionService;
use App\Services\RelativeStrengthService;

/** Explicit allowlist of canonical evidence; watchlist storage is never consulted. */
class StockInsightEvidence
{
    public function assemble(Stock $stock, ?PortfolioProfile $profile, User $user): array
    {
        if ($profile && (int) $profile->user_id !== (int) $user->id) {
            abort(403);
        }
        $limitations = [];
        $optional = function (string $label, callable $read) use (&$limitations) {
            try {
                return $read();
            } catch (\Throwable $error) {
                report($error);
                $limitations[] = $label.' unavailable';

                return null;
            }
        };
        $prices = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', today())->orderByDesc('price_date')->limit(130)->get()->reverse()->values();
        $bars = $prices->map(fn ($p) => ['date' => $p->price_date->toDateString(), 'open' => $p->open_price === null ? null : (float) $p->open_price, 'high' => $p->high_price === null ? null : (float) $p->high_price, 'low' => $p->low_price === null ? null : (float) $p->low_price, 'close' => (float) $p->close_price, 'volume' => $p->volume])->all();
        if (count($bars) < 130) {
            $limitations[] = 'Historical OHLCV incomplete';
        }
        $asOf = $prices->last()?->price_date;
        $patterns = $optional('Pattern scan', fn () => count($bars) >= 3 && ! collect($bars)->contains(fn ($b) => $b['open'] === null || $b['high'] === null || $b['low'] === null) ? app(PatternDetectionService::class)->scanBars($bars, false) : null);
        if ($patterns === null) {
            $limitations[] = 'Pattern scan unavailable';
        }
        $benchmarkSymbol = app(IndexCatalogService::class)->primarySymbol();
        $benchmark = Stock::query()->where('symbol', $benchmarkSymbol)->where('is_benchmark', true)->first();
        $rs = $optional('Relative strength', function () use ($stock, $benchmark, $asOf) {
            if (! $benchmark || ! $asOf) {
                return null;
            }
            $values = [];
            foreach ([1, 3, 6] as $months) {
                $values[$months.'m'] = app(RelativeStrengthService::class)->relativeStrength($stock, $benchmark, $months, $asOf);
            }

            return $values;
        });
        if ($rs === null || in_array(null, $rs, true)) {
            $limitations[] = 'Relative strength data incomplete';
        }
        $fundamentals = $optional('Fundamental signals', fn () => app(FundamentalSignalsService::class)->deterministicInsights($stock));
        $metrics = $optional('Fundamental metrics', fn () => app(FundamentalInvestorSnapshotService::class)->snapshot($stock)['summary']);
        $interpretation = $fundamentals ? $optional('Fundamental AI interpretation', fn () => app(FundamentalInsightReuse::class)->current($stock->id, $fundamentals)) : null;
        if ($interpretation === null) {
            $limitations[] = 'Current fundamental AI interpretation unavailable';
        }
        $period = $optional('Fundamentals period', fn () => FundamentalFact::query()->where('stock_id', $stock->id)->where('is_current', true)->whereDate('availability_date', '<=', today())->max('period_end'));
        $held = $profile && Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->where('quantity', '>', 0)->exists();
        $evidence = ['stock' => ['id' => $stock->id, 'symbol' => $stock->symbol, 'name' => $stock->name], 'ohlcv' => array_slice($bars, -30), 'relative_strength' => $rs, 'patterns' => $patterns, 'fundamentals' => $fundamentals, 'fundamental_metrics' => $metrics, 'fundamental_interpretation' => $interpretation];
        if ($held) {
            // Failure must not downgrade a held stock into global scope.
            $holding = $optional('Active portfolio valuation', fn () => collect(app(AiToolCatalog::class)->read('portfolio.holdings', [], $profile, $user)['data'])->where('stock_id', $stock->id)->values()->all());
            $evidence['holding'] = $holding;
        }
        $provenance = ['ohlcv_through' => $asOf?->toDateString(), 'fundamentals_period' => $period, 'rs_benchmark' => $rs ? $benchmarkSymbol : null, 'rs_as_of' => $rs ? $asOf?->toDateString() : null, 'pattern_as_of' => $patterns !== null ? $asOf?->toDateString() : null, 'holding_personalized' => (bool) $held];
        $evidence['data_limitations'] = array_values(array_unique($limitations));
        $evidence['data_as_of'] = $provenance;

        return ['scope' => $held ? 'account_holding' : 'global_stock', 'stock_id' => $stock->id, 'user_id' => $held ? $user->id : null, 'profile_id' => $held ? $profile->id : null, 'input' => $evidence, 'data_as_of' => $provenance];
    }
}
