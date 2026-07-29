<?php

namespace App\Engines\Discovery;

use App\Engines\Data\DataEngine;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\ScreenerRunHit;
use App\Models\Watchlist;
use App\Services\PatternScanService;
use App\Services\PortfolioLoggerService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Discovery Engine — patterns, signals, screening → candidates.
 * No dedicated Discovery Engine Specification file; behaviour from architecture docs.
 */
class DiscoveryEngine
{
    public function __construct(
        protected PatternScanService $patterns,
        protected DataEngine $data,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{run: DiscoveryRun, candidates: list<Candidate>}
     */
    public function run(PortfolioProfile $profile): array
    {
        $config = TradingOsConfig::discovery();
        $run = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => $this->data->currentDatasetVersion(),
            'status' => 'running',
            'started_at' => now(),
            'stats_json' => [],
        ]);

        try {
            $bucket = [];

            if ($config['include_patterns'] ?? true) {
                $this->collectPatternCandidates($profile, $bucket, $config['pattern_scopes'] ?? ['holdings', 'watchlist']);
            }

            if ($config['include_screener_hits'] ?? true) {
                $this->collectScreenerCandidates($profile, $bucket, (int) ($config['screener_hit_lookback_hours'] ?? 48));
            }

            // MVP fallback: if no pattern/screener signals, still evaluate holdings + watchlist
            // so the daily pipeline remains useful on quiet days (assumption A7 extended).
            if ($bucket === []) {
                $this->collectMembershipCandidates($profile, $bucket);
            }

            $max = (int) ($config['max_candidates'] ?? 100);
            $items = array_values($bucket);
            if (count($items) > $max) {
                usort($items, fn ($a, $b) => count($b['evidence']['signals'] ?? []) <=> count($a['evidence']['signals'] ?? []));
                $items = array_slice($items, 0, $max);
            }

            $candidates = [];
            DB::transaction(function () use ($run, $items, &$candidates) {
                foreach ($items as $item) {
                    $candidates[] = Candidate::query()->create([
                        'discovery_run_id' => $run->id,
                        'security_id' => $item['security_id'],
                        'source' => $item['source'],
                        'evidence' => $item['evidence'],
                        'created_at' => now(),
                    ]);
                }
            });

            $run->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'stats_json' => [
                    'candidate_count' => count($candidates),
                    'sources' => array_count_values(array_column($items, 'source')),
                ],
            ])->save();

            $this->logger->log('daily', 'DiscoveryEngine', 'info', 'Discovery run completed', [
                'profile_id' => $profile->id,
                'run_id' => $run->id,
                'candidates' => count($candidates),
            ]);

            return ['run' => $run->fresh(), 'candidates' => $candidates];
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();

            $this->logger->log('daily', 'DiscoveryEngine', 'error', 'Discovery run failed: '.$e->getMessage(), [
                'profile_id' => $profile->id,
                'run_id' => $run->id,
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, array{security_id:int,source:string,evidence:array}>  $bucket
     * @param  list<string>  $scopes
     */
    protected function collectPatternCandidates(PortfolioProfile $profile, array &$bucket, array $scopes): void
    {
        foreach ($scopes as $scope) {
            if ($scope === 'watchlist') {
                $watchlists = Watchlist::query()->where('profile_id', $profile->id)->pluck('id');
                foreach ($watchlists as $watchlistId) {
                    $scan = $this->patterns->scan($profile, 'watchlist', false, (int) $watchlistId);
                    $this->mergePatternResults($bucket, $scan['results'] ?? [], 'pattern');
                }
            } else {
                $scan = $this->patterns->scan($profile, $scope, false);
                $this->mergePatternResults($bucket, $scan['results'] ?? [], 'pattern');
            }
        }
    }

    /**
     * @param  array<int, array{security_id:int,source:string,evidence:array}>  $bucket
     * @param  list<array<string,mixed>>  $results
     */
    protected function mergePatternResults(array &$bucket, array $results, string $source): void
    {
        foreach ($results as $row) {
            $id = (int) ($row['stock_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $signals = array_map(fn ($m) => [
                'id' => $m['id'] ?? null,
                'label' => $m['label'] ?? ($m['id'] ?? null),
                'category' => $m['category'] ?? null,
            ], $row['matches'] ?? []);

            if (! isset($bucket[$id])) {
                $bucket[$id] = [
                    'security_id' => $id,
                    'source' => $source,
                    'evidence' => [
                        'symbol' => $row['symbol'] ?? null,
                        'patterns' => $signals,
                        'signals' => $signals,
                        'sources' => [$source],
                    ],
                ];
            } else {
                $bucket[$id]['evidence']['patterns'] = array_values(array_merge(
                    $bucket[$id]['evidence']['patterns'] ?? [],
                    $signals,
                ));
                $bucket[$id]['evidence']['signals'] = array_values(array_merge(
                    $bucket[$id]['evidence']['signals'] ?? [],
                    $signals,
                ));
                $bucket[$id]['evidence']['sources'][] = $source;
                $bucket[$id]['source'] = 'mixed';
            }
        }
    }

    /**
     * @param  array<int, array{security_id:int,source:string,evidence:array}>  $bucket
     */
    protected function collectScreenerCandidates(PortfolioProfile $profile, array &$bucket, int $lookbackHours): void
    {
        $since = Carbon::now()->subHours(max(1, $lookbackHours));
        $screenerIds = Screener::query()
            ->where(function ($q) use ($profile) {
                $q->where('profile_id', $profile->id)->orWhere('is_shared', true);
            })
            ->pluck('id');

        if ($screenerIds->isEmpty()) {
            return;
        }

        $runIds = ScreenerRun::query()
            ->whereIn('screener_id', $screenerIds)
            ->where('status', 'completed')
            ->where('finished_at', '>=', $since)
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return;
        }

        $hits = ScreenerRunHit::query()
            ->whereIn('run_id', $runIds)
            ->get();

        foreach ($hits as $hit) {
            $id = (int) $hit->stock_id;
            if ($id <= 0) {
                continue;
            }
            $signal = [
                'id' => 'screener_hit',
                'label' => 'Screener match',
                'screener_run_id' => $hit->run_id,
                'metrics' => $hit->metrics_json,
            ];
            if (! isset($bucket[$id])) {
                $bucket[$id] = [
                    'security_id' => $id,
                    'source' => 'screener',
                    'evidence' => [
                        'symbol' => $hit->symbol,
                        'patterns' => [],
                        'signals' => [$signal],
                        'sources' => ['screener'],
                    ],
                ];
            } else {
                $bucket[$id]['evidence']['signals'][] = $signal;
                $bucket[$id]['evidence']['sources'][] = 'screener';
                $bucket[$id]['source'] = 'mixed';
            }
        }
    }

    /**
     * @param  array<int, array{security_id:int,source:string,evidence:array}>  $bucket
     */
    protected function collectMembershipCandidates(PortfolioProfile $profile, array &$bucket): void
    {
        $holdingIds = \App\Models\Holding::query()
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->pluck('stock_id');

        foreach ($holdingIds as $id) {
            $bucket[(int) $id] = [
                'security_id' => (int) $id,
                'source' => 'holding',
                'evidence' => [
                    'patterns' => [],
                    'signals' => [['id' => 'holding_member', 'label' => 'Active holding']],
                    'sources' => ['holding'],
                ],
            ];
        }

        $watchlistIds = Watchlist::query()->where('profile_id', $profile->id)->pluck('id');
        $watchStockIds = \App\Models\WatchlistItem::query()
            ->where('profile_id', $profile->id)
            ->whereIn('watchlist_id', $watchlistIds)
            ->pluck('stock_id');

        foreach ($watchStockIds as $id) {
            $id = (int) $id;
            if (isset($bucket[$id])) {
                $bucket[$id]['evidence']['signals'][] = ['id' => 'watchlist_member', 'label' => 'Watchlist member'];
                $bucket[$id]['evidence']['sources'][] = 'watchlist';
                $bucket[$id]['source'] = 'mixed';
            } else {
                $bucket[$id] = [
                    'security_id' => $id,
                    'source' => 'watchlist',
                    'evidence' => [
                        'patterns' => [],
                        'signals' => [['id' => 'watchlist_member', 'label' => 'Watchlist member']],
                        'sources' => ['watchlist'],
                    ],
                ];
            }
        }
    }

    /**
     * @return list<Candidate>
     */
    public function listCandidates(
        ?int $discoveryRunId = null,
        ?PortfolioProfile $profile = null,
        ?string $source = null,
        ?string $search = null,
    ): array {
        $query = Candidate::query()->with(['security', 'discoveryRun', 'evaluationResult']);

        if ($discoveryRunId) {
            $query->where('discovery_run_id', $discoveryRunId);
        } elseif ($profile) {
            $latest = DiscoveryRun::query()
                ->where('profile_id', $profile->id)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->value('id');
            if (! $latest) {
                return [];
            }
            $query->where('discovery_run_id', $latest);
        }

        if ($source !== null && trim($source) !== '') {
            $query->where('source', trim($source));
        }

        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->whereHas('security', function ($q) use ($like) {
                $q->where('symbol', 'like', $like)->orWhere('name', 'like', $like);
            });
        }

        $items = $query->orderBy('id')->get()->all();

        // Prefer evaluation rank when present (latest result via Candidate::evaluationResult).
        usort($items, function (Candidate $a, Candidate $b) {
            $rankA = $a->evaluationResult?->rank;
            $rankB = $b->evaluationResult?->rank;
            if ($rankA !== null && $rankB !== null && (int) $rankA !== (int) $rankB) {
                return (int) $rankA <=> (int) $rankB;
            }
            if ($rankA !== null && $rankB === null) {
                return -1;
            }
            if ($rankA === null && $rankB !== null) {
                return 1;
            }

            return $a->id <=> $b->id;
        });

        return $items;
    }
}
