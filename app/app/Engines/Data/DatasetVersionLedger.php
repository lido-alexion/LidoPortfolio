<?php

namespace App\Engines\Data;

use App\Models\DatasetVersion;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;

/**
 * V4-FEAT-023 — records an immutable dataset version on successful market sync.
 *
 * Identity is a unique version_key derived from the successful sync instant and
 * the latest OHLCV date at that moment. Later syncs insert a new row; earlier
 * rows are never updated. This is attribution, not an OHLCV snapshot copy.
 */
class DatasetVersionLedger
{
    public const KEY_CURRENT = 'last_successful_dataset_version_key';

    public const NONE = 'none';

    public function recordSuccessfulSync(Carbon $syncedAt, string $timezone): DatasetVersion
    {
        $latestPriceDate = $this->normalizeDate(StockPrice::query()->max('price_date'));
        $version = DatasetVersion::query()->create([
            'version_key' => $this->allocateVersionKey($syncedAt, $latestPriceDate, $timezone),
            'synced_at' => $syncedAt,
            'latest_price_date' => $latestPriceDate,
            'price_bars' => (int) StockPrice::query()->count(),
            'securities_active' => (int) Stock::query()
                ->where('is_active', true)
                ->where('is_benchmark', false)
                ->count(),
            'created_at' => now(),
        ]);

        Setting::setValue(self::KEY_CURRENT, $version->version_key);

        return $version;
    }

    public function current(): ?DatasetVersion
    {
        $key = Setting::getValue(self::KEY_CURRENT);
        if (is_string($key) && trim($key) !== '' && $key !== self::NONE) {
            $found = DatasetVersion::query()->where('version_key', $key)->first();
            if ($found !== null) {
                return $found;
            }
        }

        return DatasetVersion::query()->orderByDesc('id')->first();
    }

    public function currentVersionKey(): string
    {
        return $this->current()?->version_key ?? self::NONE;
    }

    protected function allocateVersionKey(Carbon $syncedAt, ?string $latestPriceDate, string $timezone): string
    {
        $stamp = $syncedAt->copy()->timezone($timezone)->format('YmdHis');
        $asOf = $latestPriceDate !== null ? str_replace('-', '', $latestPriceDate) : 'none';
        $base = 'ds-'.$stamp.'-'.$asOf;

        if (! DatasetVersion::query()->where('version_key', $base)->exists()) {
            return $base;
        }

        $n = 2;
        while (DatasetVersion::query()->where('version_key', $base.'-'.$n)->exists()) {
            $n++;
        }

        return $base.'-'.$n;
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
