<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockClassificationObservation;
use App\Models\StockClassificationOverride;
use App\Models\StockClassificationOverrideRevision;
use App\Models\StockClassificationRefreshState;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockClassificationService
{
    public const PROVIDER = 'nse_quote_equity_free';
    public const TAXONOMY = 'nse-indices-4-tier-v1';
    public const SOURCE_URL = 'https://www.nseindia.com/api/quote-equity';
    public const CURRENT_SOURCE_URL = NseEquityQuoteService::SOURCE_URL;

    /** @return array{sectors:list<string>,industries:array<string,list<string>>,taxonomy_version:string} */
    public function options(?string $sector = null): array
    {
        $taxonomy = $this->taxonomyDefinition();
        $industries = collect($taxonomy['industries'] ?? [])
            ->map(fn (array $values): array => array_values(array_unique($values)))
            ->sortKeys();
        $pairs = StockClassificationObservation::query()
            ->where('provider', self::PROVIDER)
            ->where('taxonomy_version', self::TAXONOMY)
            ->whereNotNull('sector')
            ->whereNotNull('industry')
            ->get(['sector', 'industry'])
            ->groupBy('sector');

        $observedIndustries = $pairs
            ->map(fn ($rows): array => $rows->pluck('industry')->filter()->unique()->sort()->values()->all());
        $observedIndustries->each(function (array $values, string $sector) use (&$industries): void {
            $merged = array_values(array_unique(array_merge($industries->get($sector, []), $values)));
            sort($merged);
            $industries = $industries->put($sector, $merged);
        });
        $industries = $industries->sortKeys()->all();
        $sectors = array_keys($industries);

        return [
            'taxonomy_version' => self::TAXONOMY,
            'sectors' => $sectors,
            'industries' => $sector !== null ? [$sector => $industries[$sector] ?? []] : $industries,
        ];
    }

    /** @return array<string,mixed> */
    public function refresh(Stock $stock, ?Carbon $observedAt = null): array
    {
        if (strtoupper((string) $stock->exchange) !== 'NSE') {
            throw new \RuntimeException('Free NSE classification source only supports NSE stocks.');
        }

        $state = StockClassificationRefreshState::query()->firstOrCreate([
            'stock_id' => $stock->id, 'provider' => self::PROVIDER, 'taxonomy_version' => self::TAXONOMY,
        ]);
        $state->forceFill(['last_attempted_at' => now()])->save();
        try {
            $payload = app(NseEquityQuoteService::class)->fetch(
                (string) $stock->symbol, $stock->series, $stock->isin,
            );
            $result = $this->recordObservation($stock, $payload, $observedAt);
        } catch (\Throwable $error) {
            $attempts = (int) $state->attempts + 1;
            $state->forceFill([
                'attempts' => $attempts,
                'next_attempt_at' => now()->addMinutes(min(1440, 15 * (2 ** min($attempts - 1, 6)))),
                'last_error' => mb_substr($error->getMessage(), 0, 1000),
            ])->save();
            throw $error;
        }

        $state->forceFill([
            'attempts' => 0, 'next_attempt_at' => now()->addDays(7),
            'last_successful_at' => now(), 'last_error' => null,
        ])->save();
        return $result;
    }

    /** @param array<string,mixed> $payload */
    public function recordObservation(Stock $stock, array $payload, ?Carbon $observedAt = null): array
    {
        $current = array_key_exists('quote_metadata', $payload) || array_key_exists('equity_quote', $payload);
        if ($current) {
            [$info, $sourceUrl] = $this->currentIndustryInfo($stock, $payload);
        } else {
            // Preserve the established import/recording contract and its actual source identity.
            $info = is_array($payload['industryInfo'] ?? null) ? $payload['industryInfo'] : [];
            $sourceUrl = self::SOURCE_URL.'?symbol='.rawurlencode(strtoupper((string) $stock->symbol));
        }
        $sector = $this->label($info['sector'] ?? null);
        $industry = $this->label($info['industry'] ?? null);
        $providerSector = $sector;
        $providerIndustry = $industry;
        [$sector, $industry] = $this->mapProviderLabels($providerSector, $providerIndustry);
        $observedAt ??= now();
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $canonical);
        $existing = StockClassificationObservation::query()
            ->where('stock_id', $stock->id)
            ->where('provider', self::PROVIDER)
            ->where('taxonomy_version', self::TAXONOMY)
            ->where('raw_evidence_sha256', $hash)
            ->first();

        if ($existing === null) {
            $existing = StockClassificationObservation::query()->create([
                'stock_id' => $stock->id,
                'provider' => self::PROVIDER,
                'taxonomy_version' => self::TAXONOMY,
                'provider_sector' => $providerSector,
                'provider_industry' => $providerIndustry,
                'sector' => $sector,
                'industry' => $industry,
                'source_url' => $sourceUrl,
                'raw_evidence_sha256' => $hash,
                'raw_evidence' => $payload,
                'first_observed_at' => $observedAt,
                'observed_at' => $observedAt,
            ]);
        } else {
            $existing->forceFill(['observed_at' => $observedAt])->save();
        }

        return $this->classificationFor($stock, $existing);
    }

    /** @return array<string,mixed> */
    public function classificationFor(Stock $stock, ?StockClassificationObservation $observation = null): array
    {
        $override = StockClassificationOverride::query()
            ->where('stock_id', $stock->id)
            ->whereNull('removed_at')
            ->latest('id')
            ->first();
        if ($override !== null) {
            return [
                'source_type' => 'manual_override', 'source' => 'admin', 'taxonomy_version' => $override->taxonomy_version,
                'sector' => $override->sector, 'industry' => $override->industry,
                'observed_at' => $override->updated_at?->toIso8601String(), 'override_id' => $override->id,
            ];
        }

        $observation ??= StockClassificationObservation::query()
            ->where('stock_id', $stock->id)->latest('observed_at')->first();
        return [
            'source_type' => $observation ? 'automatic_observation' : 'unknown',
            'source' => $observation?->provider,
            'taxonomy_version' => $observation?->taxonomy_version,
            'sector' => $observation?->sector,
            'industry' => $observation?->industry,
            'observed_at' => $observation?->observed_at?->toIso8601String(),
            'observation_id' => $observation?->id,
        ];
    }

    /** @return array<string,mixed> */
    public function saveOverride(Stock $stock, int $actorId, string $sector, string $industry, ?string $reason = null): array
    {
        $sector = trim($sector);
        $industry = trim($industry);
        $options = $this->options($sector);
        if ($sector === '' || ! in_array($industry, $options['industries'][$sector] ?? [], true)) {
            throw ValidationException::withMessages(['industry' => 'Industry is not valid for the selected sector taxonomy.']);
        }

        return DB::transaction(function () use ($stock, $actorId, $sector, $industry, $reason): array {
            $override = StockClassificationOverride::query()
                ->where('stock_id', $stock->id)->whereNull('removed_at')->latest('id')->first();
            $before = $override?->only(['taxonomy_version', 'sector', 'industry', 'reason', 'removed_at']);
            $override ??= new StockClassificationOverride(['stock_id' => $stock->id]);
            $override->forceFill([
                'taxonomy_version' => self::TAXONOMY, 'sector' => $sector, 'industry' => $industry,
                'reason' => $reason, 'created_by' => $override->created_by ?? $actorId,
                'updated_by' => $actorId, 'removed_at' => null,
            ])->save();
            StockClassificationOverrideRevision::query()->create([
                'override_id' => $override->id, 'stock_id' => $stock->id, 'actor_id' => $actorId,
                'action' => $before === null ? 'created' : 'updated', 'before_payload' => $before,
                'after_payload' => $override->only(['taxonomy_version', 'sector', 'industry', 'reason', 'removed_at']),
                'created_at' => now(),
            ]);
            return $this->classificationFor($stock);
        });
    }

    public function removeOverride(Stock $stock, int $actorId): array
    {
        return DB::transaction(function () use ($stock, $actorId): array {
            $override = StockClassificationOverride::query()->where('stock_id', $stock->id)->whereNull('removed_at')->latest('id')->first();
            if ($override !== null) {
                $before = $override->only(['taxonomy_version', 'sector', 'industry', 'reason', 'removed_at']);
                $override->forceFill(['removed_at' => now(), 'updated_by' => $actorId])->save();
                StockClassificationOverrideRevision::query()->create([
                    'override_id' => $override->id, 'stock_id' => $stock->id, 'actor_id' => $actorId,
                    'action' => 'removed', 'before_payload' => $before, 'after_payload' => ['removed_at' => $override->removed_at?->toIso8601String()],
                    'created_at' => now(),
                ]);
            }
            return $this->classificationFor($stock);
        });
    }

    /** Classification policy remains here; the shared adapter owns only quote identity. */
    private function currentIndustryInfo(Stock $stock, array $payload): array
    {
        if (strtoupper((string) $stock->exchange) !== 'NSE') {
            throw new \RuntimeException('Free NSE classification source only supports NSE stocks.');
        }
        $source = app(NseEquityQuoteService::class)->inspect(
            $payload, (string) $stock->symbol, $stock->series, $stock->isin,
        );
        $info = $source['security_info'];
        if (! is_array($info)
            || ! is_string($info['sector'] ?? null) || trim($info['sector']) === ''
            || ! is_string($info['industryInfo'] ?? null) || trim($info['industryInfo']) === '') {
            throw new \RuntimeException('NSE classification quote industry fields did not match.');
        }

        return [
            ['sector' => $info['sector'], 'industry' => $info['industryInfo']],
            $source['source_url'],
        ];
    }

    private function label(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function taxonomyDefinition(): array
    {
        return (array) config('stock_classifications.taxonomies.'.self::TAXONOMY, []);
    }

    /** @return array{0:?string,1:?string} */
    private function mapProviderLabels(?string $providerSector, ?string $providerIndustry): array
    {
        if ($providerSector === null && $providerIndustry === null) {
            return [null, null];
        }

        $provider = $this->taxonomyDefinition()['providers'][self::PROVIDER] ?? [];
        $sector = $provider['sectors'][$providerSector] ?? null;
        $industry = $provider['industries'][$providerIndustry] ?? null;
        $allowed = $this->taxonomyDefinition()['industries'][$sector] ?? [];
        if ($sector === null || $industry === null || ! in_array($industry, $allowed, true)) {
            throw new \RuntimeException('NSE classification labels are not mapped to the approved taxonomy.');
        }

        return [$sector, $industry];
    }
}
