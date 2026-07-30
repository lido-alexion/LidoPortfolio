<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Services\Artifacts\Contracts\ArtifactRegistryInterface;
use App\Services\Screener\ScreenerService;
use App\Services\Screener\ScreenerVersioningService;
use InvalidArgumentException;

/**
 * Screener Artifact Registry — envelope I/O over existing portfolio_screeners (BC).
 * Does not redesign or invoke Screener execution.
 */
final class ScreenerArtifactRegistry implements ArtifactRegistryInterface
{
    public function __construct(
        private ScreenerService $screeners,
        private ArtifactValidationService $validator,
        private ScreenerVersioningService $versioning,
    ) {}

    public function type(): string
    {
        return ArtifactType::SCREENER;
    }

    public function list(?PortfolioProfile $profile = null, array $filters = []): array
    {
        if ($profile === null) {
            return [];
        }

        $includeShared = filter_var($filters['include_shared'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $out = [];

        $rows = Screener::query()
            ->where('profile_id', $profile->id)
            ->orderBy('name')
            ->get();
        foreach ($rows as $screener) {
            $env = $this->project($screener, [
                'ownership' => 'own',
                'read_only' => false,
            ]);
            if ($this->matches($env, $filters)) {
                $out[] = $env;
            }
        }

        if ($includeShared) {
            $shared = Screener::query()
                ->where('is_shared', true)
                ->where('profile_id', '!=', $profile->id)
                ->with(['profile:id,name'])
                ->orderBy('name')
                ->get();
            foreach ($shared as $screener) {
                $env = $this->project($screener, [
                    'ownership' => 'shared',
                    'read_only' => true,
                    'source_profile' => $screener->profile
                        ? ['id' => $screener->profile->id, 'name' => $screener->profile->name]
                        : null,
                ]);
                if ($this->matches($env, $filters)) {
                    $out[] = $env;
                }
            }
        }

        return $out;
    }

    public function get(string $idOrSlug, ?PortfolioProfile $profile = null): ?array
    {
        if ($profile === null) {
            return null;
        }
        $screener = $this->findScreener($profile, $idOrSlug, allowShared: true);
        if (! $screener) {
            return null;
        }
        $own = (int) $screener->profile_id === (int) $profile->id;

        return $this->project($screener, [
            'ownership' => $own ? 'own' : 'shared',
            'read_only' => ! $own,
        ]);
    }

    public function create(array $envelope, ?PortfolioProfile $profile = null): array
    {
        if ($profile === null) {
            throw new InvalidArgumentException('Portfolio context required for Screener create.');
        }
        $envelope['artifact_type'] = ArtifactType::SCREENER;
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $input = $this->envelopeToScreenerInput($envelope, $profile);
        $created = $this->screeners->create($profile, $input);
        $model = Screener::query()->where('profile_id', $profile->id)->where('id', $created['id'])->firstOrFail();

        return $this->project($model);
    }

    public function update(string $idOrSlug, array $envelope, ?PortfolioProfile $profile = null): array
    {
        if ($profile === null) {
            throw new InvalidArgumentException('Portfolio context required for Screener update.');
        }
        $screener = $this->findScreener($profile, $idOrSlug, allowShared: false);
        if (! $screener) {
            throw new InvalidArgumentException("Screener not found: {$idOrSlug}");
        }
        $envelope['artifact_type'] = ArtifactType::SCREENER;
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $this->screeners->update($screener, $this->envelopeToScreenerInput($envelope, $profile, $screener));

        return $this->project($screener->fresh());
    }

    public function validate(array $envelope, ?PortfolioProfile $profile = null): ValidationResult
    {
        $envelope['artifact_type'] = ArtifactType::SCREENER;

        return $this->validator->validateEnvelope($envelope, $profile);
    }

    public function exportOne(string $idOrSlug, ?PortfolioProfile $profile = null): array
    {
        $env = $this->get($idOrSlug, $profile);
        if ($env === null) {
            throw new InvalidArgumentException("Screener not found: {$idOrSlug}");
        }

        return $env;
    }

    /**
     * Import Screener JSON envelope into the active portfolio (always creates a new row).
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function importEnvelope(array $envelope, PortfolioProfile $profile): array
    {
        $envelope['artifact_type'] = ArtifactType::SCREENER;
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        // Avoid slug collisions on import by suffixing when needed
        $slug = (string) ($envelope['slug'] ?? '');
        if ($slug !== '' && Screener::query()->where('profile_id', $profile->id)->where('slug', $slug)->exists()) {
            $envelope['slug'] = $slug.'_import_'.substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $name = (string) ($envelope['name'] ?? 'Imported Screener');
        if (Screener::query()->where('profile_id', $profile->id)->where('name', $name)->exists()) {
            $envelope['name'] = $name.' (import)';
        }

        return $this->create($envelope, $profile);
    }

    /**
     * Copy a shared registry entry into the active portfolio.
     *
     * @return array<string, mixed>
     */
    public function importShared(PortfolioProfile $profile, int $sourceId): array
    {
        $formatted = $this->screeners->importShared($profile, $sourceId);
        $model = Screener::query()->where('profile_id', $profile->id)->where('id', $formatted['id'])->firstOrFail();

        return $this->project($model);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(string $idOrSlug, PortfolioProfile $profile): array
    {
        $screener = $this->findScreener($profile, $idOrSlug, allowShared: false);
        if (! $screener) {
            throw new InvalidArgumentException("Screener not found: {$idOrSlug}");
        }

        return $this->versioning->listVersions($screener);
    }

    /**
     * @return array{counts: array<string, int>, origins: list<array{id:string,label:string}>, statuses: list<array{id:string,label:string}>}
     */
    public function meta(PortfolioProfile $profile): array
    {
        $own = Screener::query()->where('profile_id', $profile->id)->count();
        $shared = Screener::query()
            ->where('is_shared', true)
            ->where('profile_id', '!=', $profile->id)
            ->count();
        $factory = Screener::query()->where('profile_id', $profile->id)->where('is_factory', true)->count();

        return [
            'counts' => [
                'own' => $own,
                'shared_available' => $shared,
                'factory' => $factory,
                'total_visible' => $own + $shared,
            ],
            'origins' => [
                ['id' => 'factory', 'label' => 'Factory'],
                ['id' => 'user', 'label' => 'User'],
                ['id' => 'shared', 'label' => 'Shared (other portfolios)'],
            ],
            'statuses' => [
                ['id' => 'active', 'label' => 'Active'],
                ['id' => 'draft', 'label' => 'Draft'],
                ['id' => 'deprecated', 'label' => 'Deprecated'],
            ],
            'schema_version' => ArtifactType::SCHEMA_VERSION,
            'minimum_engine_version' => ArtifactType::MINIMUM_ENGINE_VERSION,
        ];
    }

    private function findScreener(PortfolioProfile $profile, string $idOrSlug, bool $allowShared = false): ?Screener
    {
        $queryOwn = Screener::query()->where('profile_id', $profile->id);
        if (ctype_digit($idOrSlug)) {
            $found = (clone $queryOwn)->where('id', (int) $idOrSlug)->first();
            if ($found) {
                return $found;
            }
            if ($allowShared) {
                return Screener::query()
                    ->where('id', (int) $idOrSlug)
                    ->where('is_shared', true)
                    ->where('profile_id', '!=', $profile->id)
                    ->first();
            }

            return null;
        }

        $bySlug = (clone $queryOwn)->where('slug', $idOrSlug)->first();
        if ($bySlug) {
            return $bySlug;
        }

        $byKey = (clone $queryOwn)->where('factory_key', $idOrSlug)->first();
        if ($byKey) {
            return $byKey;
        }

        if ($allowShared) {
            $shared = Screener::query()
                ->where('is_shared', true)
                ->where('profile_id', '!=', $profile->id)
                ->where(function ($q) use ($idOrSlug) {
                    $q->where('slug', $idOrSlug)->orWhere('factory_key', $idOrSlug);
                })
                ->first();
            if ($shared) {
                return $shared;
            }
        }

        return Screener::query()
            ->where('profile_id', $profile->id)
            ->get()
            ->first(function (Screener $s) use ($idOrSlug) {
                $slug = $s->slug ?: ($s->factory_key ?: ('screener_'.$s->id));

                return $slug === $idOrSlug || strtolower(str_replace(' ', '_', (string) $s->name)) === strtolower($idOrSlug);
            });
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    private function project(Screener $screener, array $extraMeta = []): array
    {
        $definition = is_array($screener->definition_json) ? $screener->definition_json : ['root' => $screener->definition_json];
        if (! isset($definition['root'])) {
            $definition = ['root' => $definition];
        }
        $slug = $screener->slug ?: ($screener->factory_key ?: ('screener_'.$screener->id));
        $deps = $this->extractIndicatorDeps($definition['root'] ?? []);
        $tags = is_array($screener->tags_json) ? $screener->tags_json : [];
        if ($tags === [] && $screener->scope) {
            $tags = array_values(array_filter([$screener->scope, $screener->factory_key]));
        }

        $status = (string) ($screener->artifact_status ?: ($screener->is_enabled ? ArtifactStatus::ACTIVE : ArtifactStatus::DRAFT));
        $metadata = array_merge([
            'scope' => ArtifactScope::PORTFOLIO,
            'status' => $status,
            'origin' => $screener->is_factory ? ArtifactOrigin::FACTORY : ArtifactOrigin::USER,
            'factory_key' => $screener->factory_key,
            'description' => (string) ($screener->description ?? ''),
            'summary' => (string) ($screener->summary ?? $screener->description ?? ''),
            'intent' => (string) ($screener->intent ?? $screener->description ?? ''),
            'tags' => $tags,
            'universe' => $screener->scope,
            'storage' => 'portfolio_screeners',
            'legacy_id' => $screener->id,
            'is_shared' => (bool) $screener->is_shared,
            'is_enabled' => (bool) $screener->is_enabled,
        ], $extraMeta);

        return ArtifactEnvelope::make(
            ArtifactType::SCREENER,
            $slug,
            (string) $screener->name,
            $definition,
            $metadata,
            $deps,
            max(1, (int) ($screener->artifact_version ?? 1)),
            (string) $screener->id,
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function envelopeToScreenerInput(array $envelope, PortfolioProfile $profile, ?Screener $existing = null): array
    {
        $meta = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $definition = is_array($envelope['definition'] ?? null) ? $envelope['definition'] : ['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]];
        $tags = $meta['tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }

        $slug = (string) ($envelope['slug'] ?? $existing?->slug ?? '');
        if ($slug === '') {
            $slug = $this->versioning->slugify(
                (string) ($envelope['name'] ?? 'Imported Screener'),
                isset($meta['factory_key']) ? (string) $meta['factory_key'] : null
            );
        }

        $universe = (string) ($meta['universe'] ?? $existing?->scope ?? 'all_equities');
        $scopeMap = [
            'all_active_equities' => 'all_equities',
            'all' => 'all_equities',
            'portfolio' => 'holdings',
            'holding' => 'holdings',
        ];
        $scope = $scopeMap[$universe] ?? $universe;
        if (! in_array($scope, ['holdings', 'watchlist', 'all_equities', 'index'], true)) {
            $scope = 'all_equities';
        }

        return [
            'name' => (string) ($envelope['name'] ?? $existing?->name ?? 'Imported Screener'),
            'slug' => $slug,
            'description' => (string) ($meta['description'] ?? $meta['summary'] ?? $existing?->description ?? ''),
            'intent' => (string) ($meta['intent'] ?? $existing?->intent ?? ''),
            'summary' => (string) ($meta['summary'] ?? $existing?->summary ?? ''),
            'tags' => $tags,
            'scope' => $scope,
            'definition_json' => $definition,
            'is_enabled' => (($meta['status'] ?? ArtifactStatus::ACTIVE) === ArtifactStatus::ACTIVE)
                || (($meta['status'] ?? '') === 'active'),
            'artifact_status' => (string) ($meta['status'] ?? $existing?->artifact_status ?? ArtifactStatus::ACTIVE),
            'factory_key' => $meta['factory_key'] ?? $existing?->factory_key,
            'is_factory' => ($meta['origin'] ?? '') === ArtifactOrigin::FACTORY,
            'change_notes' => (string) ($meta['change_notes'] ?? $envelope['change_notes'] ?? 'Registry update'),
            'is_shared' => (bool) ($meta['is_shared'] ?? $existing?->is_shared ?? false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractIndicatorDeps(mixed $node): array
    {
        $ids = [];
        $walk = function ($n) use (&$walk, &$ids): void {
            if (! is_array($n)) {
                return;
            }
            if (($n['type'] ?? '') === 'group') {
                foreach ($n['children'] ?? [] as $c) {
                    $walk($c);
                }

                return;
            }
            if (($n['type'] ?? '') === 'condition') {
                foreach (['left', 'right'] as $side) {
                    $op = $n[$side] ?? null;
                    if (is_array($op) && isset($op['indicator'])) {
                        $ids[(string) $op['indicator']] = true;
                    }
                }
            }
        };
        $walk($node);
        $deps = [];
        foreach (array_keys($ids) as $id) {
            $deps[] = [
                'kind' => 'uses_indicator',
                'artifact_type' => ArtifactType::INDICATOR,
                'ref' => $id,
                'ref_scheme' => 'registry_id',
                'resolution' => 'runtime_registry',
                'required' => true,
            ];
        }

        return $deps;
    }

    /**
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $filters
     */
    private function matches(array $env, array $filters): bool
    {
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $q = mb_strtolower(trim((string) $filters['q']));
            $meta = is_array($env['metadata'] ?? null) ? $env['metadata'] : [];
            $hay = mb_strtolower(
                ($env['slug'] ?? '').' '.($env['name'] ?? '').' '.($meta['intent'] ?? '').' '.($meta['summary'] ?? '')
            );
            if (! str_contains($hay, $q)) {
                return false;
            }
        }
        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            $status = (string) (($env['metadata']['status'] ?? '') ?: '');
            if ($status !== (string) $filters['status']) {
                return false;
            }
        }
        if (isset($filters['ownership']) && trim((string) $filters['ownership']) !== '') {
            $own = (string) (($env['metadata']['ownership'] ?? 'own'));
            if ($own !== (string) $filters['ownership']) {
                return false;
            }
        }
        if (isset($filters['origin']) && trim((string) $filters['origin']) !== '') {
            $origin = (string) (($env['metadata']['origin'] ?? ''));
            $want = (string) $filters['origin'];
            if ($want === 'shared') {
                if (($env['metadata']['ownership'] ?? '') !== 'shared') {
                    return false;
                }
            } elseif ($origin !== $want) {
                return false;
            }
        }

        return true;
    }
}
