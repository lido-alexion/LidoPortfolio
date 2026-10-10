<?php

namespace App\Services;

use App\Engines\Strategy\MinerviniTrendTemplateScreener;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\ScreenerRunHit;
use App\Models\ScreenerVersion;
use App\Models\StrategyScreener;
use App\Models\TradingStrategyVersion;
use App\Services\Artifacts\ArtifactStatus;
use App\Services\Screener\ScreenerVersioningService;
use Illuminate\Validation\ValidationException;

/**
 * SD-030: Resolve Strategy eligibility exclusively via configured Screeners.
 * Recommendation Engine must not re-implement screener condition logic.
 */
class StrategyEligibilityService
{
    public const LOOKBACK_HOURS = 72;

    /**
     * Legacy Strategy versions may retain lineage-only dependencies. Never infer an
     * immutable pin from the current Screener when evaluating such a version.
     *
     * @param  array<string, mixed>  $config
     * @return list<int>
     */
    public function unresolvedPinnedScreeners(array $config, TradingStrategyVersion $version): array
    {
        $sources = is_array($config['eligibility_sources'] ?? null) ? $config['eligibility_sources'] : [];
        $exit = is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [];
        if ($exit['enabled'] ?? true) {
            foreach (is_array($exit['rules'] ?? null) ? $exit['rules'] : [] as $rule) {
                if (is_array($rule) && ($rule['key'] ?? '') === 'screener_exit' && ($rule['enabled'] ?? false)) {
                    $sources[] = $rule;
                }
            }
        }

        $links = StrategyScreener::query()->where('strategy_version_id', $version->id)
            ->get()->keyBy('screener_id');
        $unresolved = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! ($source['enabled'] ?? true)) {
                continue;
            }
            $screenerId = (int) ($source['screener_id'] ?? 0);
            if ($screenerId < 1) {
                continue;
            }
            $pin = (int) ($source['screener_version_id'] ?? 0);
            if ($pin < 1) {
                $pin = (int) ($links->get($screenerId)?->screener_version_id ?? 0);
            }
            if ($pin < 1 || ! ScreenerVersion::query()->whereKey($pin)
                ->where('screener_id', $screenerId)->exists()) {
                $unresolved[$screenerId] = $screenerId;
            }
        }

        return array_values($unresolved);
    }

    /**
     * Seed / ensure factory Minervini Trend Template screener for a profile.
     */
    public function ensureMinerviniScreener(PortfolioProfile $profile): Screener
    {
        $existing = Screener::query()
            ->where('profile_id', $profile->id)
            ->where('factory_key', MinerviniTrendTemplateScreener::FACTORY_KEY)
            ->first();

        if ($existing) {
            if (($existing->slug === null || $existing->slug === '') && \Schema::hasColumn('portfolio_screeners', 'slug')) {
                $existing->forceFill(['slug' => MinerviniTrendTemplateScreener::FACTORY_KEY])->save();
            }

            return $existing;
        }

        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => MinerviniTrendTemplateScreener::NAME,
            'description' => MinerviniTrendTemplateScreener::DESCRIPTION,
            'scope' => 'all_equities',
            'watchlist_id' => null,
            'index_symbol' => null,
            'definition_json' => MinerviniTrendTemplateScreener::definition(),
            'schedule_enabled' => false,
            'schedule_time' => null,
            'schedule_days' => null,
            'telegram_enabled' => false,
            'is_enabled' => true,
            'is_shared' => false,
            'is_factory' => true,
            'factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
            'slug' => MinerviniTrendTemplateScreener::FACTORY_KEY,
        ]);

        if (class_exists(ScreenerVersioningService::class)) {
            try {
                app(ScreenerVersioningService::class)
                    ->afterCreate($screener, 'Factory Minervini Trend Template');
            } catch (\Throwable) {
                // Registry columns may not exist yet during early migrate
            }
        }

        return $screener->fresh() ?? $screener;
    }

    /**
     * Sync junction rows for a strategy version from eligibility_sources config.
     *
     * @param  list<array<string, mixed>>  $sources
     */
    public function syncStrategyScreeners(TradingStrategyVersion $version, array $sources): void
    {
        // A persisted StrategyVersion owns an immutable dependency graph.  New
        // versions have no links yet and may be populated; existing versions
        // must never be re-pinned as a side effect of activation or repair.
        if (StrategyScreener::query()->where('strategy_version_id', $version->id)->exists()) {
            return;
        }

        $config = is_array($version->config_json) ? $version->config_json : [];
        foreach (($config['exit_strategy']['rules'] ?? []) as $rule) {
            if (is_array($rule) && ($rule['key'] ?? '') === 'screener_exit' && ($rule['enabled'] ?? false)) {
                $sources[] = $rule;
            }
        }

        StrategyScreener::query()->where('strategy_version_id', $version->id)->delete();

        $order = 0;
        $seenScreeners = [];
        foreach ($sources as $row) {
            $screenerId = (int) ($row['screener_id'] ?? 0);
            if ($screenerId < 1) {
                continue;
            }
            if (isset($seenScreeners[$screenerId])) {
                continue;
            }
            $seenScreeners[$screenerId] = true;
            $screener = Screener::query()->find($screenerId);
            if (($row['enabled'] ?? true) && $screener?->artifact_status === ArtifactStatus::ARCHIVED) {
                throw ValidationException::withMessages([
                    'eligibility_sources' => 'Archived screeners cannot be added to an enabled Strategy. Choose an active screener instead.',
                ]);
            }
            $screenerVersionId = isset($row['screener_version_id'])
                ? (int) $row['screener_version_id']
                : null;
            if ($screener) {
                $screenerVersionId ??= app(ScreenerVersioningService::class)->ensureCurrentVersion($screener)->id;
            }
            StrategyScreener::query()->create([
                'strategy_version_id' => $version->id,
                'screener_id' => $screenerId,
                'screener_version_id' => $screenerVersionId,
                'enabled' => (bool) ($row['enabled'] ?? true),
                'priority' => (int) ($row['priority'] ?? ($order + 1)),
                'display_order' => (int) ($row['display_order'] ?? $order),
            ]);
            $order++;
        }
    }

    /**
     * Resolve eligibility for recommendation generation.
     *
     * @param  array<string, mixed>  $config
     * @return array{
     *     mode: string,
     *     eligible_security_ids: list<int>,
     *     screeners: list<array<string, mixed>>,
     *     lookback_hours: int
     * }
     */
    public function resolve(PortfolioProfile $profile, array $config, ?TradingStrategyVersion $strategyVersion = null): array
    {
        $sources = $config['eligibility_sources'] ?? [];
        if (! is_array($sources) || $sources === []) {
            return [
                'mode' => 'unrestricted',
                'eligible_security_ids' => [],
                'screeners' => [],
                'lookback_hours' => self::LOOKBACK_HOURS,
            ];
        }

        $enabledSources = array_values(array_filter(
            $sources,
            fn ($s) => is_array($s) && ($s['enabled'] ?? true) && (int) ($s['screener_id'] ?? 0) > 0
        ));
        if ($strategyVersion && $this->unresolvedPinnedScreeners($config, $strategyVersion) !== []) {
            return [
                'mode' => 'provenance_unresolved',
                'eligible_security_ids' => [],
                'screeners' => [],
                'lookback_hours' => self::LOOKBACK_HOURS,
            ];
        }

        usort($enabledSources, fn ($a, $b) => ((int) ($a['priority'] ?? 0)) <=> ((int) ($b['priority'] ?? 0)));

        $since = now()->subHours(self::LOOKBACK_HOURS);
        $screenersMeta = [];
        $idSets = [];

        $pinnedVersions = $strategyVersion
            ? StrategyScreener::query()
                ->where('strategy_version_id', $strategyVersion->id)
                ->get()
                ->keyBy('screener_id')
            : collect();

        foreach ($enabledSources as $source) {
            $screenerId = (int) $source['screener_id'];
            $screener = Screener::query()
                ->where('id', $screenerId)
                ->ownedOrSameUserShared($profile)
                ->first();

            if ($screener?->artifact_status === ArtifactStatus::ARCHIVED) {
                return [
                    'mode' => 'provenance_unresolved',
                    'eligible_security_ids' => [],
                    'screeners' => [],
                    'lookback_hours' => self::LOOKBACK_HOURS,
                ];
            }

            $name = $screener?->name ?? (string) ($source['screener_name'] ?? 'Screener #'.$screenerId);
            $run = null;
            $hitIds = [];
            $pinnedVersionId = (int) ($source['screener_version_id'] ?? 0);
            if ($pinnedVersionId < 1 && $pinnedVersions->has($screenerId)) {
                $pinnedVersionId = (int) $pinnedVersions->get($screenerId)->screener_version_id;
            }

            if ($screener && ($strategyVersion === null || $pinnedVersionId > 0)) {
                $run = ScreenerRun::query()
                    ->where('screener_id', $screener->id)
                    ->when($pinnedVersionId > 0, fn ($q) => $q->where('screener_version_id', $pinnedVersionId))
                    ->where('status', 'completed')
                    ->where('finished_at', '>=', $since)
                    ->orderByDesc('id')
                    ->first();

                if ($run) {
                    $hitIds = ScreenerRunHit::query()
                        ->where('run_id', $run->id)
                        ->pluck('stock_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all();
                }
            }

            $status = 'NO_RUN';
            if ($screener === null) {
                $status = 'MISSING';
            } elseif ($strategyVersion !== null && $pinnedVersionId < 1) {
                $status = 'UNPINNED';
            } elseif ($run && $hitIds !== []) {
                $status = 'PASS';
                $idSets[] = $hitIds;
            } elseif ($run) {
                $status = 'EMPTY';
                $idSets[] = [];
            }

            $screenersMeta[] = [
                'screener_id' => $screenerId,
                'screener_version_id' => $pinnedVersionId > 0 ? $pinnedVersionId : null,
                'name' => $name,
                'priority' => (int) ($source['priority'] ?? 0),
                'enabled' => true,
                'status' => $status,
                'run_id' => $run?->id,
                'hit_count' => count($hitIds),
                'security_ids' => $hitIds,
            ];
        }

        // Union of enabled screener hits (stock eligible if it passes ANY enabled screener).
        $eligible = [];
        foreach ($idSets as $set) {
            foreach ($set as $id) {
                $eligible[$id] = true;
            }
        }
        $eligibleIds = array_map('intval', array_keys($eligible));
        sort($eligibleIds);

        $hasAnyRun = collect($screenersMeta)->contains(
            fn ($row) => in_array($row['status'], ['PASS', 'EMPTY'], true)
        );

        // V1: if configured screeners have not been run recently, fall back to unrestricted
        // evaluation candidates so install/demo still works — evidence flags the pending state.
        if (! $hasAnyRun) {
            return [
                'mode' => 'unrestricted_pending_screener_runs',
                'eligible_security_ids' => [],
                'screeners' => $screenersMeta,
                'lookback_hours' => self::LOOKBACK_HOURS,
            ];
        }

        return [
            'mode' => 'screener_union',
            'eligible_security_ids' => $eligibleIds,
            'screeners' => $screenersMeta,
            'lookback_hours' => self::LOOKBACK_HOURS,
        ];
    }

    /**
     * Resolve stock IDs from enabled Screener Exit rules (latest completed run within lookback).
     *
     * @param  array<string, mixed>  $exitConfig
     * @return array{
     *     by_screener: array<int, list<int>>,
     *     meta: list<array<string, mixed>>
     * }
     */
    public function resolveExitScreenerHits(PortfolioProfile $profile, array $exitConfig, ?TradingStrategyVersion $strategyVersion = null): array
    {
        $byScreener = [];
        $meta = [];
        if (! ($exitConfig['enabled'] ?? true)) {
            return ['by_screener' => [], 'meta' => []];
        }

        $rules = is_array($exitConfig['rules'] ?? null) ? $exitConfig['rules'] : [];
        $since = now()->subHours(self::LOOKBACK_HOURS);

        foreach ($rules as $rule) {
            if (! is_array($rule) || ($rule['key'] ?? '') !== 'screener_exit' || ! ($rule['enabled'] ?? false)) {
                continue;
            }
            $screenerId = (int) ($rule['screener_id'] ?? 0);
            if ($screenerId < 1) {
                $meta[] = [
                    'screener_id' => 0,
                    'name' => (string) ($rule['screener_name'] ?? 'Unassigned'),
                    'status' => 'UNASSIGNED',
                    'run_id' => null,
                    'hit_count' => 0,
                ];

                continue;
            }

            $screener = Screener::query()
                ->where('id', $screenerId)
                ->ownedOrSameUserShared($profile)
                ->first();

            $name = $screener?->name ?? (string) ($rule['screener_name'] ?? 'Screener #'.$screenerId);
            $run = null;
            $hitIds = [];
            $pinnedVersionId = (int) ($rule['screener_version_id'] ?? 0);
            if ($pinnedVersionId < 1 && $strategyVersion) {
                $pinnedVersionId = (int) StrategyScreener::query()
                    ->where('strategy_version_id', $strategyVersion->id)
                    ->where('screener_id', $screenerId)
                    ->value('screener_version_id');
            }

            if ($screener && ($strategyVersion === null || $pinnedVersionId > 0)) {
                $run = ScreenerRun::query()
                    ->where('screener_id', $screener->id)
                    ->when($pinnedVersionId > 0, fn ($q) => $q->where('screener_version_id', $pinnedVersionId))
                    ->where('status', 'completed')
                    ->where('finished_at', '>=', $since)
                    ->orderByDesc('id')
                    ->first();

                if ($run) {
                    $hitIds = ScreenerRunHit::query()
                        ->where('run_id', $run->id)
                        ->pluck('stock_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all();
                }
            }

            $status = 'NO_RUN';
            if ($screener === null) {
                $status = 'MISSING';
            } elseif ($strategyVersion !== null && $pinnedVersionId < 1) {
                $status = 'UNPINNED';
            } elseif ($run && $hitIds !== []) {
                $status = 'PASS';
            } elseif ($run) {
                $status = 'EMPTY';
            }

            $byScreener[$screenerId] = $hitIds;
            $meta[] = [
                'screener_id' => $screenerId,
                'screener_version_id' => $pinnedVersionId > 0 ? $pinnedVersionId : null,
                'name' => $name,
                'status' => $status,
                'run_id' => $run?->id,
                'hit_count' => count($hitIds),
            ];
        }

        return [
            'by_screener' => $byScreener,
            'meta' => $meta,
        ];
    }

    /**
     * Per-stock screener pass map for explainability.
     *
     * @param  array{screeners: list<array<string, mixed>>}  $eligibility
     * @return list<array{screener_id:int,name:string,status:string}>
     */
    public function explainForSecurity(array $eligibility, int $securityId): array
    {
        $out = [];
        foreach ($eligibility['screeners'] ?? [] as $row) {
            $ids = $row['security_ids'] ?? [];
            $status = $row['status'];
            if ($status === 'PASS' || $status === 'EMPTY') {
                $status = in_array($securityId, $ids, true) ? 'PASS' : 'FAIL';
            }
            $out[] = [
                'screener_id' => (int) $row['screener_id'],
                'name' => (string) $row['name'],
                'status' => $status,
            ];
        }

        return $out;
    }
}