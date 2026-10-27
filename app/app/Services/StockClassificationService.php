<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockClassificationObservation;
use App\Models\StockClassificationOverride;
use App\Models\StockClassificationOverrideRevision;
use App\Models\StockClassificationRefreshState;
use App\Support\NseHttpClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockClassificationService
{
    public const PROVIDER = 'nse_quote_equity_free';
    public const TAXONOMY = 'nse-indices-4-tier-v1';
    public const SOURCE_URL = 'https://www.nseindia.com/api/quote-equity';
    public const CURRENT_SOURCE_URL = 'https://www.nseindia.com/api/NextApi/apiClient/GetQuoteApi';

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
            $client = NseHttpClient::create();
            $metadata = $this->requestPayload($client, [
                'functionName' => 'getMetaData', 'symbol' => strtoupper((string) $stock->symbol),
            ]);
            [$series, $marketType] = $this->validateMetadata($stock, $metadata);
            $quote = $this->requestPayload($client, $this->quoteQuery($stock, $series, $marketType));
            // Retain both original decoded responses, not a fabricated legacy payload.
            $payload = ['quote_metadata' => $metadata, 'equity_quote' => $quote];
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

    private function requestPayload(\Illuminate\Http\Client\PendingRequest $client, array $query): array
    {
        try {
            $response = $client->get(self::CURRENT_SOURCE_URL, $query);
        } catch (\Illuminate\Http\Client\RequestException $error) {
            throw new \RuntimeException('NSE classification request failed with HTTP '.$error->response->status(), 0, $error);
        }
        if (! $response->successful()) {
            throw new \RuntimeException('NSE classification request failed with HTTP '.$response->status());
        }
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException('NSE classification response was not a JSON object.');
        }

        return $payload;
    }

    /** Metadata is source identity/selection evidence, not permission to change lifecycle. */
    private function validateMetadata(Stock $stock, array $metadata): array
    {
        $symbol = strtoupper((string) $stock->symbol);
        $series = strtoupper(trim((string) $stock->series));
        $isin = $metadata['isin'] ?? null;
        $marketType = $metadata['marketType'] ?? null;
        $activeSeries = $metadata['activeSeries'] ?? null;
        if (strtoupper((string) $stock->exchange) !== 'NSE'
            || ($metadata['symbol'] ?? null) !== $symbol
            || ! is_string($isin) || preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin) !== 1
            || (trim((string) $stock->isin) !== '' && strtoupper(trim((string) $stock->isin)) !== $isin)
            || ! is_string($marketType) || preg_match('/^[A-Z0-9]{1,8}$/', $marketType) !== 1
            || ! is_array($activeSeries) || ! array_is_list($activeSeries) || count($activeSeries) > 20
            || $activeSeries === []) {
            throw new \RuntimeException('NSE classification metadata identity or series did not match the requested stock.');
        }
        foreach ($activeSeries as $offered) {
            if (! is_string($offered) || preg_match('/^[A-Z0-9]{1,4}$/', $offered) !== 1) {
                throw new \RuntimeException('NSE classification offered series was malformed.');
            }
        }
        if ($series === '') {
            // EQ remains the ordinary default when offered. A single explicit
            // alternative is unambiguous; never guess among other choices.
            $choices = array_values(array_unique($activeSeries));
            $series = in_array('EQ', $choices, true) ? 'EQ' : (count($choices) === 1 ? $choices[0] : '');
        }
        if (preg_match('/^[A-Z0-9]{1,4}$/', $series) !== 1 || ! in_array($series, $activeSeries, true)) {
            throw new \RuntimeException('NSE classification metadata did not offer the requested stock series.');
        }

        return [$series, $marketType];
    }

    private function quoteQuery(Stock $stock, string $series, string $marketType): array
    {
        return [
            'functionName' => 'getSymbolData', 'marketType' => $marketType,
            'series' => $series, 'symbol' => strtoupper((string) $stock->symbol),
        ];
    }

    /** Accept one exact current quote; unknown wrappers and partial 200 responses fail closed. */
    private function currentIndustryInfo(Stock $stock, array $payload): array
    {
        $metadata = $payload['quote_metadata'] ?? null;
        $quote = $payload['equity_quote'] ?? null;
        if (! is_array($metadata) || ! is_array($quote)) {
            throw new \RuntimeException('NSE classification evidence envelope was incomplete.');
        }
        [$series, $marketType] = $this->validateMetadata($stock, $metadata);
        $responses = $quote['equityResponse'] ?? null;
        if (! is_array($responses) || ! array_is_list($responses) || count($responses) !== 1
            || ! is_array($responses[0]['metaData'] ?? null) || ! is_array($responses[0]['secInfo'] ?? null)) {
            throw new \RuntimeException('NSE classification quote response shape was invalid or ambiguous.');
        }
        $identity = $responses[0]['metaData'];
        $info = $responses[0]['secInfo'];
        if (($identity['symbol'] ?? null) !== $metadata['symbol']
            || ($identity['isinCode'] ?? null) !== $metadata['isin']
            || ($identity['series'] ?? null) !== $series
            || ($identity['marketType'] ?? null) !== $marketType
            || ! is_string($info['sector'] ?? null) || trim($info['sector']) === ''
            || ! is_string($info['industryInfo'] ?? null) || trim($info['industryInfo']) === '') {
            throw new \RuntimeException('NSE classification quote identity or industry fields did not match.');
        }

        return [
            ['sector' => $info['sector'], 'industry' => $info['industryInfo']],
            self::CURRENT_SOURCE_URL.'?'.http_build_query($this->quoteQuery($stock, $series, $marketType), '', '&', PHP_QUERY_RFC3986),
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
