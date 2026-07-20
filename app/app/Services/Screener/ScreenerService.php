<?php

namespace App\Services\Screener;

use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Models\Watchlist;
use App\Services\ExternalStockLinkService;
use App\Services\IndexCatalogService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ScreenerService
{
    private const NAME_ALLOWED_PATTERN = '/^[\pL\pN\s\-\._,\&\(\)\/:\+\#%\'"]+$/u';

    private const DESCRIPTION_ALLOWED_PATTERN = '/^[\pL\pN\s\-\._,\&\(\)\/:\+\#%\'"\?\!]*$/u';

    public function __construct(
        protected ScreenerDefinitionValidator $validator,
        protected ExternalStockLinkService $externalStockLinks,
        protected IndexCatalogService $indexCatalog,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function meta(): array
    {
        $meta = ScreenerCatalog::meta();
        $meta['external_stock_links'] = $this->externalStockLinks->enabledTemplates();
        $meta['indexes'] = $this->constituentCapableIndexes();

        return $meta;
    }

    /**
     * Indexes that can be used as a screener universe (NSE broad/sector with constituents).
     *
     * @return list<array{symbol:string,name:string,exchange:string}>
     */
    public function constituentCapableIndexes(): array
    {
        $out = [];
        foreach ($this->indexCatalog->enabledDefinitions() as $def) {
            if (! $this->indexCatalog->supportsConstituents($def)) {
                continue;
            }
            $out[] = [
                'symbol' => $def['symbol'],
                'name' => $def['name'],
                'exchange' => $def['exchange'],
            ];
        }

        return $out;
    }

    /**
     * @return Collection<int, Screener>
     */
    public function listForProfile(PortfolioProfile $profile): Collection
    {
        return Screener::query()
            ->where('profile_id', $profile->id)
            ->with([
                'watchlist:id,name',
                'runs' => fn ($q) => $q->orderByDesc('id')->limit(1),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Screener $s) => $this->format($s));
    }

    /**
     * @return Collection<int, array<string,mixed>>
     */
    public function listSharedForProfile(PortfolioProfile $profile): Collection
    {
        return Screener::query()
            ->where('is_shared', true)
            ->where('profile_id', '!=', $profile->id)
            ->with(['profile:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Screener $s) => $this->formatShared($s));
    }

    /**
     * Copy a shared screener from another portfolio into the active portfolio.
     *
     * @return array<string,mixed>
     */
    public function importShared(PortfolioProfile $profile, int $sourceId): array
    {
        $source = Screener::query()
            ->where('id', $sourceId)
            ->where('is_shared', true)
            ->where('profile_id', '!=', $profile->id)
            ->firstOrFail();

        $scope = $source->scope === 'watchlist' ? 'holdings' : $source->scope;
        $name = $this->uniqueNameForProfile($profile, $source->name);
        $indexSymbol = $scope === 'index' ? $source->index_symbol : null;

        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'description' => $source->description,
            'scope' => $scope,
            'watchlist_id' => null,
            'index_symbol' => $indexSymbol,
            'definition_json' => $source->definition_json,
            'schedule_enabled' => false,
            'schedule_time' => null,
            'schedule_days' => [],
            'telegram_enabled' => false,
            'is_enabled' => true,
            'is_shared' => false,
        ]);

        return $this->format($screener->fresh(['watchlist:id,name']));
    }

    public function find(PortfolioProfile $profile, int $id): Screener
    {
        return Screener::query()
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function create(PortfolioProfile $profile, array $input): array
    {
        $data = $this->normalizeInput($profile, $input, null);
        $screener = Screener::query()->create($data);

        return $this->format($screener->fresh(['watchlist:id,name']));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function update(Screener $screener, array $input): array
    {
        $profile = PortfolioProfile::query()->findOrFail($screener->profile_id);
        $data = $this->normalizeInput($profile, $input, $screener);
        $screener->fill($data);
        $screener->save();

        return $this->format($screener->fresh(['watchlist:id,name']));
    }

    public function delete(Screener $screener): void
    {
        $screener->delete();
    }

    /**
     * @return array<string,mixed>
     */
    public function format(Screener $screener): array
    {
        $definition = is_array($screener->definition_json) ? $screener->definition_json : ['root' => $screener->definition_json];
        $eval = app(ScreenerEvaluationService::class);

        return [
            'id' => $screener->id,
            'profile_id' => $screener->profile_id,
            'name' => $screener->name,
            'description' => $screener->description,
            'scope' => $screener->scope,
            'watchlist_id' => $screener->watchlist_id,
            'index_symbol' => $screener->index_symbol,
            'index' => $this->formatIndexRef($screener),
            'watchlist' => $screener->relationLoaded('watchlist') && $screener->watchlist
                ? ['id' => $screener->watchlist->id, 'name' => $screener->watchlist->name]
                : null,
            'definition_json' => $definition,
            'max_lookback' => $eval->maxLookback($definition),
            'schedule_enabled' => (bool) $screener->schedule_enabled,
            'schedule_time' => $screener->schedule_time,
            'schedule_days' => $screener->schedule_days ?? [],
            'telegram_enabled' => (bool) $screener->telegram_enabled,
            'is_enabled' => (bool) $screener->is_enabled,
            'is_shared' => (bool) $screener->is_shared,
            'watchlist_issue' => $this->watchlistIssue($screener),
            'index_issue' => $this->indexIssue($screener),
            'last_run_at' => optional($screener->last_run_at)?->toIso8601String(),
            'last_run' => $this->formatLastRunSummary(
                $screener->relationLoaded('runs') ? $screener->runs->first() : null
            ),
            'created_at' => optional($screener->created_at)?->toIso8601String(),
            'updated_at' => optional($screener->updated_at)?->toIso8601String(),
        ];
    }

    private function watchlistIssue(Screener $screener): ?string
    {
        if ($screener->scope !== 'watchlist') {
            return null;
        }

        if (! $screener->watchlist_id) {
            return 'Watchlist missing or was deleted. Select a watchlist to run this screener.';
        }

        if ($screener->relationLoaded('watchlist') && $screener->watchlist === null) {
            return 'Watchlist no longer exists. Select a different watchlist.';
        }

        return null;
    }

    private function indexIssue(Screener $screener): ?string
    {
        if ($screener->scope !== 'index') {
            return null;
        }

        $symbol = strtoupper(trim((string) $screener->index_symbol));
        if ($symbol === '') {
            return 'Index missing. Select an index to run this screener.';
        }

        $def = $this->indexCatalog->definitionForSymbol($symbol);
        if ($def === null || ! $this->indexCatalog->supportsConstituents($def)) {
            return 'Index is not supported for constituents. Choose another index.';
        }

        return null;
    }

    /**
     * @return array{symbol:string,name:string}|null
     */
    private function formatIndexRef(Screener $screener): ?array
    {
        $symbol = strtoupper(trim((string) $screener->index_symbol));
        if ($symbol === '') {
            return null;
        }
        $def = $this->indexCatalog->definitionForSymbol($symbol);

        return [
            'symbol' => $symbol,
            'name' => $def['name'] ?? $symbol,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function formatLastRunSummary(?ScreenerRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $stats = $run->stats_json ?? [];

        return [
            'id' => $run->id,
            'status' => $run->status,
            'finished_at' => optional($run->finished_at)?->toIso8601String(),
            'stats' => [
                'matched' => (int) ($stats['matched'] ?? 0),
                'scanned' => (int) ($stats['scanned'] ?? 0),
                'skipped_insufficient_data' => (int) ($stats['skipped_insufficient_data'] ?? 0),
                'errors' => (int) ($stats['errors'] ?? 0),
                'warnings' => is_array($stats['warnings'] ?? null) ? $stats['warnings'] : [],
            ],
            'error_message' => $run->error_message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function formatShared(Screener $screener): array
    {
        $row = $this->format($screener);
        $row['source_profile'] = $screener->relationLoaded('profile') && $screener->profile
            ? ['id' => $screener->profile->id, 'name' => $screener->profile->name]
            : null;

        return $row;
    }

    private function uniqueNameForProfile(PortfolioProfile $profile, string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'Imported screener';
        }
        $name = $base;
        $suffix = 2;
        while (Screener::query()->where('profile_id', $profile->id)->where('name', $name)->exists()) {
            $name = $base.' ('.$suffix.')';
            $suffix++;
        }

        return $name;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalizeInput(PortfolioProfile $profile, array $input, ?Screener $existing): array
    {
        $name = trim((string) ($input['name'] ?? $existing?->name ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name is required.']);
        }
        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Name max 120 characters.']);
        }
        if (! preg_match(self::NAME_ALLOWED_PATTERN, $name)) {
            throw ValidationException::withMessages(['name' => 'Name has unsupported characters.']);
        }

        $dup = Screener::query()
            ->where('profile_id', $profile->id)
            ->where('name', $name)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($dup) {
            throw ValidationException::withMessages(['name' => 'A screener with this name already exists.']);
        }

        $scope = (string) ($input['scope'] ?? $existing?->scope ?? 'holdings');
        if (! in_array($scope, ScreenerCatalog::SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => 'Invalid scope.']);
        }

        $watchlistId = $input['watchlist_id'] ?? $existing?->watchlist_id;
        if ($scope === 'watchlist') {
            if (! $watchlistId) {
                throw ValidationException::withMessages(['watchlist_id' => 'Watchlist is required for watchlist scope.']);
            }
            $wl = Watchlist::query()
                ->where('profile_id', $profile->id)
                ->where('id', (int) $watchlistId)
                ->first();
            if ($wl === null) {
                throw ValidationException::withMessages(['watchlist_id' => 'Watchlist not found.']);
            }
            $watchlistId = $wl->id;
        } else {
            $watchlistId = null;
        }

        $indexSymbol = null;
        if ($scope === 'index') {
            $rawIndex = $input['index_symbol'] ?? $existing?->index_symbol ?? '';
            $indexSymbol = strtoupper(trim((string) $rawIndex));
            if ($indexSymbol === '') {
                throw ValidationException::withMessages(['index_symbol' => 'Index is required for index scope.']);
            }
            $def = $this->indexCatalog->definitionForSymbol($indexSymbol);
            if ($def === null || ! $this->indexCatalog->supportsConstituents($def)) {
                throw ValidationException::withMessages(['index_symbol' => 'Index does not support constituents.']);
            }
            $indexSymbol = $def['symbol'];
        }

        $definitionInput = $input['definition_json'] ?? $existing?->definition_json ?? null;
        if (! is_array($definitionInput)) {
            throw ValidationException::withMessages(['definition_json' => 'definition_json is required.']);
        }
        try {
            $definition = $this->validator->validate($definitionInput);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['definition_json' => $e->getMessage()]);
        }

        $scheduleEnabled = (bool) ($input['schedule_enabled'] ?? $existing?->schedule_enabled ?? false);
        $scheduleTime = $input['schedule_time'] ?? $existing?->schedule_time;
        if ($scheduleEnabled) {
            if (! is_string($scheduleTime) || ! preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) {
                throw ValidationException::withMessages(['schedule_time' => 'schedule_time must be HH:mm.']);
            }
        } else {
            $scheduleTime = is_string($scheduleTime) && preg_match('/^\d{2}:\d{2}$/', $scheduleTime)
                ? $scheduleTime
                : null;
        }

        $scheduleDays = $input['schedule_days'] ?? $existing?->schedule_days ?? [];
        if (! is_array($scheduleDays)) {
            $scheduleDays = [];
        }
        $scheduleDays = array_values(array_unique(array_map('intval', $scheduleDays)));
        foreach ($scheduleDays as $d) {
            if ($d < 0 || $d > 6) {
                throw ValidationException::withMessages(['schedule_days' => 'Days must be 0–6 (Sun–Sat).']);
            }
        }

        $description = $input['description'] ?? $existing?->description;
        if ($description !== null) {
            $description = trim((string) $description);
            if ($description === '') {
                $description = null;
            } elseif (mb_strlen($description) > 500) {
                throw ValidationException::withMessages(['description' => 'Description max 500 characters.']);
            } elseif (! preg_match(self::DESCRIPTION_ALLOWED_PATTERN, $description)) {
                throw ValidationException::withMessages(['description' => 'Description has unsupported characters.']);
            }
        }

        return [
            'profile_id' => $profile->id,
            'name' => $name,
            'description' => $description,
            'scope' => $scope,
            'watchlist_id' => $watchlistId,
            'index_symbol' => $indexSymbol,
            'definition_json' => $definition,
            'schedule_enabled' => $scheduleEnabled,
            'schedule_time' => $scheduleTime,
            'schedule_days' => $scheduleDays,
            'telegram_enabled' => (bool) ($input['telegram_enabled'] ?? $existing?->telegram_enabled ?? false),
            'is_enabled' => (bool) ($input['is_enabled'] ?? $existing?->is_enabled ?? true),
            'is_shared' => (bool) ($input['is_shared'] ?? $existing?->is_shared ?? false),
        ];
    }

    /**
     * Default empty definition for new screeners in UI.
     *
     * @return array{root:array}
     */
    public static function defaultDefinition(): array
    {
        return [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'ema', 'params' => ['period' => 50]],
                        'operator' => 'gt',
                        'weight_factor' => 1,
                        'right' => ['indicator' => 'ema', 'params' => ['period' => 200]],
                    ],
                ],
            ],
        ];
    }
}
