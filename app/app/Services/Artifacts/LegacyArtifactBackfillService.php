<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Models\PortfolioProfile;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\Screener;
use App\Models\TradingStrategy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class LegacyArtifactBackfillService
{
    public function __construct(
        private ScreenerArtifactRegistry $screeners,
        private StrategyArtifactRegistry $strategies,
        private ReusableArtifactLifecycleService $lifecycle,
        private ArtifactBindingService $bindings,
    ) {}

    /** @return array{created:int,skipped:int,failed:int,failures:list<array<string,mixed>>} */
    public function backfill(?PortfolioProfile $onlyProfile = null): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => []];
        $profiles = PortfolioProfile::query()->with('user')->when($onlyProfile, fn ($query) => $query->whereKey($onlyProfile->id))->orderBy('id')->get();
        foreach ($profiles as $profile) {
            foreach (Screener::query()->where('profile_id', $profile->id)->orderBy('id')->get() as $screener) {
                $this->attempt($result, 'screener', $screener->id, fn () => $this->backfillScreener($profile, $screener));
            }
            foreach (TradingStrategy::query()->where('profile_id', $profile->id)->orderBy('id')->get() as $strategy) {
                $this->attempt($result, 'strategy', $strategy->id, fn () => $this->backfillStrategy($profile, $strategy));
            }
        }

        return $result;
    }

    private function backfillScreener(PortfolioProfile $profile, Screener $screener): bool
    {
        if ($screener->reusable_artifact_id !== null) {
            return false;
        }

        return DB::transaction(function () use ($profile, $screener) {
            $envelope = $this->screeners->exportOne((string) $screener->id, $profile);
            $artifactVersion = $this->createPublished(
                $profile,
                $envelope,
                $screener->is_factory ? ArtifactOrigin::FACTORY : ArtifactOrigin::USER,
                'portfolio_screeners',
                $screener->id,
                ['legacy_artifact_version' => (int) ($screener->artifact_version ?? 1)],
            );
            $binding = $this->bindings->bind($profile, $artifactVersion, $profile->user, [
                'schedule_enabled' => (bool) $screener->schedule_enabled,
                'schedule_time' => $screener->schedule_time,
                'schedule_days' => $screener->schedule_days ?? [],
                'telegram_enabled' => (bool) $screener->telegram_enabled,
            ], (bool) $screener->is_enabled);
            $screener->forceFill(['reusable_artifact_id' => $artifactVersion->artifact_id])->save();
            if ($screener->artifact_status === ArtifactStatus::ARCHIVED) {
                $this->lifecycle->archive($artifactVersion->artifact, $profile->user);
                if ($binding->status === ArtifactBinding::STATUS_ENABLED) {
                    throw new InvalidArgumentException('Archived legacy Screener cannot have an enabled binding.');
                }
            }

            return true;
        });
    }

    private function backfillStrategy(PortfolioProfile $profile, TradingStrategy $strategy): bool
    {
        if ($strategy->reusable_artifact_id !== null) {
            return false;
        }

        return DB::transaction(function () use ($profile, $strategy) {
            $envelope = $this->strategies->exportOne((string) $strategy->id, $profile);
            $legacyVersionId = (int) ($strategy->active_version_id ?? 0);
            $artifactVersion = $this->createPublished(
                $profile,
                $envelope,
                $strategy->is_factory ? ArtifactOrigin::FACTORY : ArtifactOrigin::USER,
                'portfolio_tos_strategies',
                $strategy->id,
                ['legacy_strategy_version_id' => $legacyVersionId],
            );
            $binding = $this->bindings->bind($profile, $artifactVersion, $profile->user, [
                'allocation_pct' => $strategy->allocation_pct !== null ? (float) $strategy->allocation_pct : 100.0,
            ], $strategy->status === TradingStrategy::STATUS_ACTIVE);
            $strategy->forceFill(['reusable_artifact_id' => $artifactVersion->artifact_id])->save();
            if ($strategy->status === TradingStrategy::STATUS_ARCHIVED) {
                $this->lifecycle->archive($artifactVersion->artifact, $profile->user);
                if ($binding->status === ArtifactBinding::STATUS_ENABLED) {
                    throw new InvalidArgumentException('Archived legacy Strategy cannot have an enabled binding.');
                }
            }

            return true;
        });
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $extraProvenance
     */
    private function createPublished(
        PortfolioProfile $profile,
        array $envelope,
        string $origin,
        string $legacyTable,
        int $legacyId,
        array $extraProvenance,
    ): ReusableArtifactVersion {
        $type = (string) $envelope['artifact_type'];
        $slug = $this->uniqueSlug($profile, $type, (string) $envelope['slug'], $legacyId);
        $envelope['slug'] = $slug;
        $metadata = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $metadata['status'] = ArtifactStatus::DRAFT;
        $metadata['origin'] = $origin;
        $envelope['metadata'] = $metadata;
        $provenance = array_merge([
            'kind' => 'legacy_runtime_backfill',
            'legacy_table' => $legacyTable,
            'legacy_id' => $legacyId,
            'legacy_profile_id' => $profile->id,
        ], $extraProvenance);
        $draft = $this->lifecycle->createDraft(
            $profile->user,
            $type,
            $slug,
            (string) $envelope['name'],
            $envelope,
            '1.0.0',
            $origin,
            $provenance,
        );

        return $this->lifecycle->publish(
            $draft,
            $profile->user,
            $this->dependencies($profile, $envelope),
            'Initial immutable version backfilled from '.$legacyTable.' #'.$legacyId,
        );
    }

    /** @param array<string,mixed> $envelope @return list<array<string,mixed>> */
    private function dependencies(PortfolioProfile $profile, array $envelope): array
    {
        $dependencies = [];
        foreach ($envelope['dependencies'] ?? [] as $dependency) {
            if (! is_array($dependency)) {
                continue;
            }
            if (($dependency['artifact_type'] ?? null) === ArtifactType::INDICATOR) {
                $dependencies[] = [
                    'kind' => $dependency['kind'] ?? 'uses_indicator',
                    'indicator_id' => $dependency['ref'] ?? null,
                    'indicator_version' => $dependency['ref_version'] ?? null,
                    'required' => $dependency['required'] ?? true,
                ];

                continue;
            }
            if (($dependency['artifact_type'] ?? null) === ArtifactType::SCREENER) {
                $ref = (string) ($dependency['ref'] ?? '');
                $screener = Screener::query()->where('profile_id', $profile->id)
                    ->where(fn ($query) => $query->where('slug', $ref)->orWhere('factory_key', $ref))
                    ->first();
                $version = $screener?->reusableArtifact?->versions()
                    ->where('status', ReusableArtifactVersion::STATUS_PUBLISHED)->latest('id')->first();
                if (! $version) {
                    throw new InvalidArgumentException("Strategy dependency {$ref} was not backfilled.");
                }
                $dependencies[] = [
                    'kind' => $dependency['kind'] ?? 'uses_screener',
                    'artifact_version_id' => $version->id,
                    'required' => $dependency['required'] ?? true,
                ];
            }
        }

        return $dependencies;
    }

    private function uniqueSlug(PortfolioProfile $profile, string $type, string $slug, int $legacyId): string
    {
        $base = trim($slug) !== '' ? trim($slug) : $type.'_'.$legacyId;
        if (! ReusableArtifact::query()->where('owner_user_id', $profile->user_id)->where('artifact_type', $type)->where('slug', $base)->exists()) {
            return $base;
        }

        return $base.'_legacy_p'.$profile->id.'_'.$legacyId;
    }

    /** @param array{created:int,skipped:int,failed:int,failures:list<array<string,mixed>>} $result */
    private function attempt(array &$result, string $type, int $legacyId, callable $operation): void
    {
        try {
            $operation() ? $result['created']++ : $result['skipped']++;
        } catch (Throwable $error) {
            $result['failed']++;
            $result['failures'][] = [
                'type' => $type,
                'legacy_id' => $legacyId,
                'error' => $error->getMessage(),
            ];
        }
    }
}
