<?php

namespace App\Services\AI;

use App\Models\{PortfolioProfile, User, Watchlist, Stock, TradingStrategy, Screener, ScreenerRun, TradingRecommendation, ReusableArtifact, ReusableArtifactVersion, AnalysisPreference, DashboardLayout};
use App\Services\{WatchlistService, CashManagementService};
use App\Services\Analytics\PortfolioAnalyticsService;
use App\Services\Artifacts\{StrategyArtifactRegistry, ScreenerArtifactRegistry, LegacyArtifactAuthoringService, ReusableArtifactLifecycleService};
use Illuminate\Support\Facades\Validator;

/** Closed tool catalog. No endpoint names, SQL, broker operations or executable code from callers. */
class AiToolCatalog
{
    public const READS = ['portfolio.summary', 'portfolio.holdings', 'portfolio.analytics', 'cash.summary', 'watchlist.list', 'watchlist.items', 'strategy.list', 'screener.list', 'screener.runs', 'recommendations.list', 'artifact.list', 'preferences.read', 'dashboard.list', 'workflow.prepare'];
    public const MUTATIONS = ['watchlist.create', 'watchlist.rename', 'watchlist.add_stock', 'watchlist.remove_stock', 'watchlist.delete', 'strategy.create', 'strategy.update', 'screener.create', 'screener.update', 'artifact.update_draft'];
    public const DESTRUCTIVE = ['watchlist.remove_stock', 'watchlist.delete'];

    public function catalog(array $scopes): array
    {
        $tools = in_array('portfolio:read', $scopes, true) ? self::READS : [];
        if (in_array('portfolio:write', $scopes, true)) $tools = [...$tools, ...self::MUTATIONS];
        return array_map(fn ($tool) => ['id' => $tool, 'side_effect' => $this->kind($tool), 'confirmation_required' => ! in_array($tool, self::READS, true), 'scope' => in_array($tool, self::READS, true) ? 'portfolio:read' : 'portfolio:write', 'idempotency' => 'run_and_plan_step', 'input_rules' => $this->rules($tool)], $tools);
    }

    public function kind(string $tool): string
    {
        if (in_array($tool, self::READS, true)) return 'read';
        if (in_array($tool, self::DESTRUCTIVE, true)) return 'destructive';
        if (in_array($tool, self::MUTATIONS, true)) return 'mutation';
        throw new AiToolFailure('tool_not_allowed');
    }

    private function rules(string $tool): array
    {
        $id = ['required', 'integer', 'min:1'];
        $name = ['required', 'string', 'max:120'];
        return match ($tool) {
            'watchlist.items', 'watchlist.delete', 'screener.runs' => ['id' => $id],
            'watchlist.create' => ['name' => $name],
            'watchlist.rename' => ['id' => $id, 'name' => $name],
            'watchlist.add_stock' => ['id' => $id, 'stock_id' => $id, 'note' => ['nullable', 'string', 'max:500']],
            'watchlist.remove_stock' => ['id' => $id, 'stock_id' => $id],
            'strategy.create', 'screener.create' => ['envelope' => ['required', 'array']],
            'strategy.update', 'screener.update' => ['id' => $id, 'envelope' => ['required', 'array']],
            'artifact.update_draft' => ['id' => $id, 'content' => ['required', 'array']],
            default => [],
        };
    }

    public function validate(string $tool, array $arguments): array
    {
        $this->kind($tool);
        $rules = $this->rules($tool);
        if (array_diff(array_keys($arguments), array_keys($rules))) throw new AiToolFailure('malformed_input');
        foreach ($rules as $field => $constraints) if (in_array('integer', $constraints, true) && isset($arguments[$field]) && ! is_int($arguments[$field])) throw new AiToolFailure('malformed_input');
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > 64000) throw new AiToolFailure('input_too_large');
        return Validator::make($arguments, $rules)->validate();
    }

    private function watchlist(PortfolioProfile $profile, int $id): Watchlist
    {
        return Watchlist::query()->where('profile_id', $profile->id)->lockForUpdate()->findOrFail($id);
    }

    /** Read paths deliberately avoid lazy initialization used by UI controllers. */
    public function read(string $tool, array $a, PortfolioProfile $p, User $user): array
    {
        if (! in_array($tool, self::READS, true)) throw new AiToolFailure('read_tool_required');
        $a = $this->validate($tool, $a);
        $data = match ($tool) {
            'portfolio.summary', 'portfolio.analytics' => app(PortfolioAnalyticsService::class)->readForProfile($p),
            'portfolio.holdings' => $this->holdings($p),
            'cash.summary' => ['availability' => 'available', 'data' => ['profile' => app(CashManagementService::class)->readSummary($p), 'account' => app(CashManagementService::class)->readAccountSummary($user)]],
            'watchlist.list' => app(WatchlistService::class)->readWatchlistsForProfile($p),
            'watchlist.items' => $this->available(Watchlist::query()->where('profile_id', $p->id)->findOrFail($a['id'])->items()->orderBy('id')->get(['id', 'watchlist_id', 'stock_id', 'note'])->toArray()),
            'strategy.list' => app(StrategyArtifactRegistry::class)->readForProfile($p),
            'screener.list' => $this->available(app(ScreenerArtifactRegistry::class)->list($p, ['include_shared' => false])),
            'screener.runs' => $this->available(ScreenerRun::query()->where('screener_id', Screener::query()->where('profile_id', $p->id)->findOrFail($a['id'])->id)->latest('id')->limit(20)->get()->toArray()),
            'recommendations.list' => $this->available(TradingRecommendation::query()->where('profile_id', $p->id)->latest('id')->limit(50)->get(['id', 'security_id', 'recommendation_type', 'status', 'created_at'])->toArray()),
            'artifact.list' => $this->available(ReusableArtifact::query()->where('owner_user_id', $user->id)->whereIn('artifact_type', ['strategy', 'screener'])->with('versions')->orderBy('id')->get()->toArray()),
            'preferences.read' => $this->available(AnalysisPreference::query()->where('user_id', $user->id)->whereIn('scope_key', ['account', 'portfolio:'.$p->id])->get()->toArray()),
            'dashboard.list' => $this->available(DashboardLayout::query()->where('user_id', $user->id)->orderBy('id')->get()->toArray()),
            'workflow.prepare' => ['availability' => 'available', 'data' => ['preparation_only' => true, 'broker_trading_available' => false, 'next_step' => 'Review the workflow in StoX. Broker execution is unavailable to this assistant.']],
        };
        if (strlen(json_encode($data)) > 128000) return ['availability' => 'incomplete', 'data' => null, 'reason' => 'response_size_limit'];
        return $data;
    }

    private function holdings(PortfolioProfile $profile): array
    {
        $summary = app(\App\Services\PortfolioCalculationService::class)->calculateForProfile($profile);
        $rows = $summary['holdings'];
        $complete = true;
        foreach ($rows as &$row) {
            $row['availability'] = $row['latest_close'] > 0 ? 'available' : 'incomplete';
            if ($row['availability'] !== 'available') {
                $complete = false;
                foreach (['latest_close', 'market_value', 'unrealized_profit', 'allocation_market_percent'] as $field) $row[$field] = null;
            }
        }
        unset($row);
        if (! $complete) foreach ($rows as &$row) $row['allocation_market_percent'] = null;
        return ['availability' => $rows === [] ? 'not_initialized' : ($complete ? 'available' : 'incomplete'), 'data' => $rows];
    }

    private function available(array $rows): array
    {
        return ['availability' => $rows === [] ? 'not_initialized' : 'available', 'data' => $rows];
    }

    /** State used for previews is read again in the execution transaction. */
    public function snapshot(string $tool, array $a, PortfolioProfile $p, User $user): array
    {
        $this->validate($tool, $a);
        if (str_starts_with($tool, 'watchlist.')) {
            if ($tool === 'watchlist.create') return Watchlist::query()->where('profile_id', $p->id)->orderBy('id')->lockForUpdate()->get()->toArray();
            $row = $this->watchlist($p, $a['id']);
            return ['watchlist' => $row->toArray(), 'items' => $row->items()->orderBy('id')->lockForUpdate()->get()->toArray()];
        }
        if ($tool === 'artifact.update_draft') {
            $row = ReusableArtifactVersion::query()->whereHas('artifact', fn ($q) => $q->where('owner_user_id', $user->id)->whereIn('artifact_type', ['strategy', 'screener']))->lockForUpdate()->findOrFail($a['id']);
            if ($row->status !== 'draft') throw new AiToolFailure('immutable_version');
            return $row->toArray();
        }
        if (str_ends_with($tool, '.create')) return ReusableArtifact::query()->where('owner_user_id', $user->id)->orderBy('id')->lockForUpdate()->get()->toArray();
        $model = $tool === 'strategy.update' ? TradingStrategy::class : Screener::class;
        $row = $model::query()->where('profile_id', $p->id)->lockForUpdate()->findOrFail($a['id']);
        if ($row->reusable_artifact_id !== null || $row->is_factory) throw new AiToolFailure('library_or_factory_immutable');
        return $tool === 'strategy.update' ? ['object' => $row->toArray(), 'versions' => $row->versions()->orderBy('id')->lockForUpdate()->get()->toArray()] : $row->toArray();
    }

    public function preview(string $tool, array $a, PortfolioProfile $p, User $user): array
    {
        if ($this->kind($tool) === 'read') throw new AiToolFailure('mutation_tool_required');
        $a = $this->validate($tool, $a);
        $snapshot = $this->snapshot($tool, $a, $p, $user);
        if (str_starts_with($tool, 'watchlist.') && isset($a['name'])) {
            $a['name'] = app(WatchlistService::class)->normalizeWatchlistName($a['name']);
            if (Watchlist::query()->where('profile_id', $p->id)->where('name', $a['name'])->when(isset($a['id']), fn ($q) => $q->where('id', '!=', $a['id']))->exists()) throw new AiToolFailure('watchlist_name_conflict');
            if ($tool === 'watchlist.create' && count($snapshot) >= WatchlistService::MAX_WATCHLISTS_PER_PROFILE) throw new AiToolFailure('watchlist_limit');
        }
        if ($tool === 'watchlist.add_stock') {
            $stock = Stock::query()->findOrFail($a['stock_id']);
            if ($stock->is_benchmark || ! $stock->isEffectivelyActive()) throw new AiToolFailure('stock_unavailable');
            if (count($snapshot['items']) >= WatchlistService::MAX_ITEMS_PER_WATCHLIST || collect($snapshot['items'])->contains('stock_id', $a['stock_id'])) throw new AiToolFailure('watchlist_item_conflict');
        }
        if ($tool === 'watchlist.remove_stock' && ! collect($snapshot['items'])->contains('stock_id', $a['stock_id'])) throw new AiToolFailure('not_found', 404);

        if ($tool === 'screener.update') {
            if (! $snapshot['is_enabled']) throw new AiToolFailure('disabled_screener_update_not_supported');
            if (($a['envelope']['metadata']['status'] ?? $snapshot['artifact_status']) !== $snapshot['artifact_status'] || ($a['envelope']['metadata']['is_enabled'] ?? true) !== true) throw new AiToolFailure('lifecycle_change_not_supported');
        }
        if ($tool === 'strategy.update' && ($a['envelope']['metadata']['status'] ?? $snapshot['object']['status']) !== $snapshot['object']['status']) throw new AiToolFailure('lifecycle_change_not_supported');
        if (isset($a['envelope'])) {
            $registry = str_starts_with($tool, 'strategy.') ? app(StrategyArtifactRegistry::class) : app(ScreenerArtifactRegistry::class);
            if (! $registry->validate($a['envelope'], $p)->ok) throw new AiToolFailure('artifact_validation_failed');
        }
        if ($tool === 'artifact.update_draft') {
            $type = ReusableArtifactVersion::query()->findOrFail($a['id'])->artifact->artifact_type;
            if (($a['content']['artifact_type'] ?? null) !== $type) throw new AiToolFailure('artifact_type_mismatch');
            $registry = $type === 'strategy' ? app(StrategyArtifactRegistry::class) : app(ScreenerArtifactRegistry::class);
            if (! $registry->validate($a['content'], $p)->ok) throw new AiToolFailure('artifact_validation_failed');
        }
        if ($tool === 'watchlist.delete' && Watchlist::query()->where('profile_id', $p->id)->count() <= 1) throw new AiToolFailure('only_watchlist_protected');
        return ['tool' => $tool, 'arguments' => $a, 'state_hash' => $this->hash($snapshot), 'side_effect' => $this->kind($tool),
            'affected_object_id' => $a['id'] ?? null, 'validation' => 'passed', 'changes' => $this->changes($tool, $a, $snapshot, $p),
            'consequence' => str_ends_with($tool, '.create') && ! str_starts_with($tool, 'watchlist.') ? 'Create a Library draft only. Publishing and binding remain separate.' : str_replace('.', ' ', $tool),
            'warning' => in_array($tool, self::DESTRUCTIVE, true) ? 'Deletes the selected watchlist data. This cannot be undone by the assistant.' : null];
    }

    private function changes(string $tool, array $arguments, array $snapshot, PortfolioProfile $profile): array
    {
        $after = $arguments['envelope'] ?? $arguments['content'] ?? $arguments;
        $before = [];
        if ($tool === 'artifact.update_draft') $before = $snapshot['content_json'];
        if ($tool === 'screener.update') $before = app(ScreenerArtifactRegistry::class)->get((string) $arguments['id'], $profile) ?? [];
        if ($tool === 'strategy.update') {
            foreach (app(StrategyArtifactRegistry::class)->readForProfile($profile)['data'] as $row) {
                if ((int) ($row['data']['metadata']['legacy_id'] ?? 0) === $arguments['id']) $before = $row['data'];
            }
        }
        $flatten = function ($value, string $prefix = '') use (&$flatten): array {
            if (! is_array($value) || $value === []) return [$prefix => $value];
            $out = [];
            foreach ($value as $key => $item) $out += $flatten($item, $prefix === '' ? (string) $key : $prefix.'.'.$key);
            return $out;
        };
        $old = $flatten($before); $new = $flatten($after); $changes = [];
        foreach ($new as $path => $value) if (! array_key_exists($path, $old) || $old[$path] !== $value) $changes[] = ['field' => $path, 'before' => $old[$path] ?? null, 'after' => $value];
        if (isset($arguments['envelope']) || isset($arguments['content'])) foreach ($old as $path => $value) if ($path !== '' && ! array_key_exists($path, $new)) $changes[] = ['field' => $path, 'before' => $value, 'after' => null];
        if (count($changes) > 100) throw new AiToolFailure('preview_too_large');
        return $changes;
    }

    public function hash(mixed $value): string
    {
        $sort = function ($item) use (&$sort) { if (! is_array($item)) return $item; if (! array_is_list($item)) ksort($item); return array_map($sort, $item); };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    public function mutate(string $tool, array $a, PortfolioProfile $p, User $user): array
    {
        $service = app(WatchlistService::class);
        $result = match ($tool) {
            'watchlist.create' => $service->createWatchlist($p, $a['name']),
            'watchlist.rename' => $service->renameWatchlist($this->watchlist($p, $a['id']), $a['name']),
            'watchlist.add_stock' => $service->add($this->watchlist($p, $a['id']), Stock::query()->findOrFail($a['stock_id']), $a['note'] ?? null),
            'watchlist.remove_stock' => $this->removeStock($p, $a),
            'watchlist.delete' => $this->deleteWatchlist($p, $a),
            'strategy.create', 'screener.create' => app(LegacyArtifactAuthoringService::class)->createDraft($p, [...$a['envelope'], 'artifact_type' => str_starts_with($tool, 'strategy.') ? 'strategy' : 'screener']),
            'strategy.update' => app(StrategyArtifactRegistry::class)->update((string) $a['id'], $a['envelope'], $p),
            'screener.update' => app(ScreenerArtifactRegistry::class)->update((string) $a['id'], $a['envelope'], $p),
            'artifact.update_draft' => $this->updateDraft($user, $a),
            default => throw new AiToolFailure('tool_not_allowed'),
        };
        return is_array($result) ? $result : [];
    }

    private function removeStock(PortfolioProfile $p, array $a): array
    {
        $item = $this->watchlist($p, $a['id'])->items()->where('stock_id', $a['stock_id'])->firstOrFail();
        app(WatchlistService::class)->remove($item);
        return ['id' => $a['id'], 'stock_id' => $a['stock_id'], 'deleted' => true];
    }
    private function deleteWatchlist(PortfolioProfile $p, array $a): array
    {
        app(WatchlistService::class)->deleteWatchlist($this->watchlist($p, $a['id']));
        return ['id' => $a['id'], 'deleted' => true];
    }
    private function updateDraft(User $user, array $a): array
    {
        $draft = ReusableArtifactVersion::query()->findOrFail($a['id']);
        return app(ReusableArtifactLifecycleService::class)->updateDraft($draft, $user, $draft->lock_version, $a['content'], $draft->documentation_json ?? [])->toArray();
    }

    /** Fresh reads verify the intended result; service return values alone are not verification. */
    private function containsIntent(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected)) return $this->hash($actual) === $this->hash($expected);
        if (! is_array($actual)) return false;
        foreach ($expected as $key => $value) if (! array_key_exists($key, $actual) || ! $this->containsIntent($actual[$key], $value)) return false;
        return true;
    }

    public function verify(string $tool, array $a, array $result, PortfolioProfile $p, User $user): bool
    {
        if ($tool === 'watchlist.delete') return ! Watchlist::query()->where('profile_id', $p->id)->whereKey($a['id'])->exists();
        if ($tool === 'watchlist.remove_stock') return ! $this->watchlist($p, $a['id'])->items()->where('stock_id', $a['stock_id'])->exists();
        if ($tool === 'watchlist.add_stock') return $this->watchlist($p, $a['id'])->items()->where('stock_id', $a['stock_id'])->where('note', app(WatchlistService::class)->normalizeNote($a['note'] ?? null))->exists();
        if (in_array($tool, ['watchlist.create', 'watchlist.rename'], true)) return Watchlist::query()->where('profile_id', $p->id)->whereKey($result['id'])->where('name', trim($a['name']))->exists();
        if (str_ends_with($tool, '.create')) return ReusableArtifactVersion::query()->whereHas('artifact', fn ($q) => $q->where('owner_user_id', $user->id))->whereKey($result['reusable_artifact_version_id'])->where('status', 'draft')->exists();
        if ($tool === 'artifact.update_draft') return $this->hash(ReusableArtifactVersion::query()->findOrFail($a['id'])->content_json) === $this->hash($a['content']);
        $fresh = $tool === 'strategy.update' ? app(StrategyArtifactRegistry::class)->readForProfile($p) : $this->available(app(ScreenerArtifactRegistry::class)->list($p, ['include_shared' => false]));
        $rows = $tool === 'strategy.update' ? array_column($fresh['data'], 'data') : $fresh['data'];
        foreach ($rows as $row) {
            if ((int) ($row['metadata']['legacy_id'] ?? 0) !== $a['id']) continue;
            $intent = $a['envelope'];
            if (($intent['name'] ?? $row['name']) !== $row['name']) return false;
            if (! $this->containsIntent($row['definition'], $intent['definition'] ?? [])) return false;
            foreach (['description', 'intent', 'summary', 'tags', 'status', 'universe', 'is_enabled', 'is_shared'] as $field) {
                if (! array_key_exists($field, $intent['metadata'] ?? [])) continue;
                $expected = $intent['metadata'][$field];
                $actual = $row['metadata'][$field] ?? null;
                if (in_array($field, ['description', 'intent', 'summary'], true)) { $expected = (string) ($expected ?? ''); $actual = (string) ($actual ?? ''); }
                if (! $this->containsIntent($actual, $expected)) return false;
            }
            // List-only ownership labels are presentation metadata, not mutation state.
            $row['metadata'] = array_intersect_key($row['metadata'], $result['metadata']);
            return $this->hash($row) === $this->hash($result);
        }
        return false;
    }
}
