<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlHistoricalUniverseMembershipService;

/**
 * FEAT-062 — lightweight sector peer context for fundamental insights.
 */
class FundamentalSectorContextService
{
    /** @var list<array{key: string, label: string, metric: string, needs_price: bool}> */
    private const PEER_METRICS = [
        ['key' => 'roe_ttm', 'label' => 'ROE (TTM)', 'metric' => 'roe', 'needs_price' => false],
        ['key' => 'operating_margin_ttm', 'label' => 'Operating margin (TTM)', 'metric' => 'operating_margin', 'needs_price' => false],
        ['key' => 'debt_equity', 'label' => 'Debt / equity', 'metric' => 'debt_equity', 'needs_price' => false],
        ['key' => 'pe_ratio', 'label' => 'P/E (TTM)', 'metric' => 'pe', 'needs_price' => true],
    ];

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalInvestorSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function contextFor(Stock $stock, ?Carbon $asOf = null, ?float $price = null): ?array
    {
        $sector = trim((string) ($stock->sector ?? ''));
        if ($sector === '') {
            return null;
        }

        $asOf ??= now();
        $snapshotMembers = MlUniverseMembership::query()
            ->where('universe_key', MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE)
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf->toDateString());
            })
            ->whereNotNull('sector_snapshot')
            ->with('stock')
            ->get();

        if ($snapshotMembers->isEmpty()) {
            return [
                'sector' => $sector,
                'peer_count' => 0,
                'coverage_status' => 'missing_historical_universe_snapshot',
                'summary' => 'Historical sector peer context is unavailable for this date.',
            ];
        }

        $subjectMembership = $snapshotMembers->firstWhere('stock_id', $stock->id);
        $sector = trim((string) ($subjectMembership?->sector_snapshot ?? $sector));
        $peers = $snapshotMembers
            ->filter(fn (MlUniverseMembership $membership): bool => (int) $membership->stock_id !== (int) $stock->id && (string) $membership->sector_snapshot === $sector)
            ->map(fn (MlUniverseMembership $membership): ?Stock => $membership->stock)
            ->filter()
            ->unique('id')
            ->take(40)
            ->values();

        if ($peers->isEmpty()) {
            return [
                'sector' => $sector,
                'peer_count' => 0,
                'coverage_status' => 'covered_no_peers',
                'summary' => 'No historical sector peers in the StoX universe for comparison.',
            ];
        }

        $peerPercentiles = [];
        $highlights = [];

        foreach (self::PEER_METRICS as $definition) {
            $peerValues = $this->collectMetric(
                $peers,
                $definition['metric'],
                $asOf,
                $definition['needs_price'] ? $price : null,
                $definition['needs_price'],
            );
            $stockValue = $this->fundamentals->metric(
                $stock,
                $definition['metric'],
                'ttm',
                $asOf,
                $definition['needs_price'] ? $price : null,
            )['value'];

            if ($stockValue === null || $peerValues->isEmpty()) {
                continue;
            }

            $percentile = $this->percentileRank((float) $stockValue, $peerValues);
            $sorted = $peerValues->sort()->values();
            $median = $sorted[(int) floor(($sorted->count() - 1) / 2)];

            $peerPercentiles[] = [
                'metric_key' => $definition['key'],
                'label' => $definition['label'],
                'value' => round((float) $stockValue, 4),
                'peer_median' => round($median, 4),
                'percentile' => round($percentile, 4),
            ];

            if ($definition['key'] === 'roe_ttm') {
                if ($percentile >= 0.75) {
                    $highlights[] = 'ROE (TTM) ranks in the top quartile versus sector peers.';
                } elseif ($percentile <= 0.25) {
                    $highlights[] = 'ROE (TTM) ranks in the bottom quartile versus sector peers.';
                }
            }
        }

        $roeRow = collect($peerPercentiles)->firstWhere('metric_key', 'roe_ttm');

        return [
            'sector' => $sector,
            'peer_count' => $peers->count(),
            'coverage_status' => 'covered',
            'roe_ttm_percentile' => $roeRow['percentile'] ?? null,
            'peer_percentiles' => $peerPercentiles,
            'highlights' => $highlights,
            'summary' => $highlights[0] ?? 'Sector peer context available; compare key metrics versus peer median.',
        ];
    }

    /**
     * @param  Collection<int, Stock>  $peers
     * @return Collection<int, float>
     */
    private function collectMetric(
        Collection $peers,
        string $metric,
        Carbon $asOf,
        ?float $subjectPrice,
        bool $needsPrice,
    ): Collection {
        return $peers->map(function (Stock $peer) use ($metric, $asOf, $subjectPrice, $needsPrice): ?float {
            $peerPrice = $needsPrice ? $this->snapshots->latestMarketPrice($peer, $asOf) : null;
            $value = $this->fundamentals->metric($peer, $metric, 'ttm', $asOf, $peerPrice)['value'];

            return $value !== null ? (float) $value : null;
        })->filter(fn (?float $v): bool => $v !== null)->values();
    }

    /**
     * @param  Collection<int, float>  $population
     */
    private function percentileRank(float $value, Collection $population): float
    {
        $sorted = $population->sort()->values();
        $below = $sorted->filter(fn (float $v): bool => $v < $value)->count();
        $equal = $sorted->filter(fn (float $v): bool => abs($v - $value) < 1e-9)->count();
        $n = max(1, $sorted->count());

        return ($below + 0.5 * $equal) / $n;
    }
}
