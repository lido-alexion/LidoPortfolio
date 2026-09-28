<?php

namespace App\Services\ML;

use App\Contracts\MlHistoricalUniverseProvider;
use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\Stock;
use Carbon\Carbon;

/**
 * Reads an immutable, provider-exported dated universe archive. There is no
 * current-universe fallback: an absent date is a retryable coverage failure.
 */
class ConfiguredHistoricalUniverseProvider implements MlHistoricalUniverseProvider
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $archive = null;

    public function snapshotForDate(string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $archive = $this->loadArchive();
        $row = $archive[$date] ?? null;
        if (! is_array($row)) {
            throw new MlHistoricalUniverseProviderException("Historical universe date unavailable: {$date}", true);
        }
        $members = $row['memberships'] ?? $row['members'] ?? null;
        if (! is_array($members)) {
            throw new MlHistoricalUniverseProviderException("Malformed historical universe response: {$date}");
        }

        $seen = [];
        $normalized = [];
        foreach ($members as $member) {
            if (! is_array($member)) {
                throw new MlHistoricalUniverseProviderException("Malformed historical universe member: {$date}");
            }
            $stockId = $this->resolveStockId($member, $date);
            if (isset($seen[$stockId])) {
                throw new MlHistoricalUniverseProviderException("Duplicate canonical stock in historical universe: {$date}");
            }
            $seen[$stockId] = true;
            $normalized[] = [
                'stock_id' => $stockId,
                'sector_snapshot' => $member['sector_snapshot'] ?? $member['sector'] ?? null,
                'provider_symbol' => isset($member['symbol']) ? strtoupper(trim((string) $member['symbol'])) : null,
                'provider_token' => isset($member['token']) ? (string) $member['token'] : null,
                'exchange' => isset($member['exchange']) ? strtoupper(trim((string) $member['exchange'])) : 'NSE',
            ];
        }

        return [
            'effective_from' => $date,
            'source' => (string) ($row['source'] ?? config('ml.historical_universe.source', 'configured_authoritative_archive')),
            'snapshot_key' => (string) ($row['snapshot_key'] ?? $date),
            'response_version' => isset($row['response_version']) ? (string) $row['response_version'] : null,
            'memberships' => $normalized,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function loadArchive(): array
    {
        if ($this->archive !== null) {
            return $this->archive;
        }
        $path = (string) config('ml.historical_universe.archive_path', '');
        if ($path === '' || ! is_file($path)) {
            throw new MlHistoricalUniverseProviderException('Configured historical universe archive is unavailable.', true);
        }
        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new MlHistoricalUniverseProviderException('Historical universe archive is malformed: '.$e->getMessage());
        }
        $rows = is_array($payload['snapshots'] ?? null) ? $payload['snapshots'] : $payload;
        if (! is_array($rows)) {
            throw new MlHistoricalUniverseProviderException('Historical universe archive must contain snapshots.');
        }
        $archive = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['effective_from'])) {
                throw new MlHistoricalUniverseProviderException('Historical universe archive contains an invalid snapshot.');
            }
            $date = Carbon::parse((string) $row['effective_from'])->toDateString();
            if (isset($archive[$date])) {
                throw new MlHistoricalUniverseProviderException("Duplicate historical universe date: {$date}");
            }
            $archive[$date] = $row;
        }
        return $this->archive = $archive;
    }

    /** @param array<string,mixed> $member */
    private function resolveStockId(array $member, string $date): int
    {
        if (isset($member['stock_id'])) {
            $stock = Stock::query()->whereKey((int) $member['stock_id'])->where('exchange', 'NSE')->first();
        } else {
            $symbol = strtoupper(trim((string) ($member['symbol'] ?? '')));
            $stock = $symbol === '' ? null : Stock::query()->where('exchange', 'NSE')->where('symbol', $symbol)->first();
        }
        if ($stock === null) {
            throw new MlHistoricalUniverseProviderException("Unable to map historical universe member to canonical StoX stock: {$date}");
        }
        return (int) $stock->id;
    }
}
