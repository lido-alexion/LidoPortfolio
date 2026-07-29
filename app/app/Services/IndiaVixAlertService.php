<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Support\IndiaVixScale;

class IndiaVixAlertService
{
    public const SYMBOL = 'INDIAVIX';

    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected TelegramNotificationService $telegram,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * Evaluate latest India VIX close against each portfolio's threshold.
     * Notifies via Telegram on cross above (armed while at/below, fires once while above).
     *
     * @return array{evaluated: bool, vix: float|null, price_date: string|null, notified: int, rearmed: int, skipped: int}
     */
    public function evaluateAndNotify(): array
    {
        $latest = $this->latestVixClose();
        if ($latest === null) {
            return [
                'evaluated' => false,
                'vix' => null,
                'price_date' => null,
                'notified' => 0,
                'rearmed' => 0,
                'skipped' => 0,
            ];
        }

        $vix = $latest['close'];
        $priceDate = $latest['price_date'];
        $notified = 0;
        $rearmed = 0;
        $skipped = 0;

        foreach (PortfolioProfile::query()->orderBy('id')->get() as $profile) {
            $result = $this->evaluateProfile($profile, $vix, $priceDate);
            if ($result === 'notified') {
                $notified++;
            } elseif ($result === 'rearmed') {
                $rearmed++;
            } else {
                $skipped++;
            }
        }

        if ($notified > 0) {
            $this->logger->scheduler('info', 'India VIX threshold alerts sent', [
                'category' => 'IndiaVixAlert',
                'vix' => $vix,
                'price_date' => $priceDate,
                'notified' => $notified,
            ]);
        }

        return [
            'evaluated' => true,
            'vix' => $vix,
            'price_date' => $priceDate,
            'notified' => $notified,
            'rearmed' => $rearmed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return 'notified'|'rearmed'|'skipped'
     */
    public function evaluateProfile(PortfolioProfile $profile, float $vix, string $priceDate): string
    {
        if ($this->profileSettings->get($profile, 'indiavix_alert_enabled', 'true') !== 'true') {
            return 'skipped';
        }

        $threshold = (float) $this->profileSettings->get($profile, 'indiavix_alert_threshold', '20');
        if ($threshold <= 0) {
            return 'skipped';
        }

        $armed = $this->profileSettings->isIndiaVixAlertArmed($profile);

        if ($vix > $threshold) {
            if (! $armed) {
                return 'skipped';
            }

            $message = $this->formatMessage($profile, $vix, $priceDate, $threshold);
            if (! $this->telegram->sendMessageForProfile($profile, $message)) {
                return 'skipped';
            }

            $this->profileSettings->setIndiaVixAlertArmed($profile, false);

            return 'notified';
        }

        if (! $armed) {
            $this->profileSettings->setIndiaVixAlertArmed($profile, true);

            return 'rearmed';
        }

        return 'skipped';
    }

    /**
     * @return array{close: float, price_date: string}|null
     */
    public function latestVixClose(): ?array
    {
        $stock = Stock::query()
            ->where('symbol', self::SYMBOL)
            ->where('is_benchmark', true)
            ->orderBy('id')
            ->first();

        if ($stock === null) {
            return null;
        }

        $row = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->first();

        if ($row === null || $row->close_price === null) {
            return null;
        }

        $close = (float) $row->close_price;
        if (IndiaVixScale::needsRescale($close)) {
            $normalized = IndiaVixScale::normalizeRow([
                'open_price' => $row->open_price,
                'high_price' => $row->high_price,
                'low_price' => $row->low_price,
                'close_price' => $row->close_price,
                'adjusted_close_price' => $row->adjusted_close_price,
            ]);
            $row->fill([
                'open_price' => $normalized['open_price'] ?? $row->open_price,
                'high_price' => $normalized['high_price'] ?? $row->high_price,
                'low_price' => $normalized['low_price'] ?? $row->low_price,
                'close_price' => $normalized['close_price'],
                'adjusted_close_price' => $normalized['adjusted_close_price']
                    ?? $normalized['close_price'],
            ]);
            $row->save();
            $close = (float) $row->close_price;
        }

        return [
            'close' => $close,
            'price_date' => $row->price_date?->toDateString() ?? (string) $row->getRawOriginal('price_date'),
        ];
    }

    protected function formatMessage(
        PortfolioProfile $profile,
        float $vix,
        string $priceDate,
        float $threshold,
    ): string {
        $name = trim((string) $profile->name) !== '' ? $profile->name : 'Portfolio';

        return sprintf(
            "India VIX alert (%s)\nIndia VIX closed at %s on %s, above your threshold of %s.\nTelegram alerts stay off until VIX falls back to or below the threshold.",
            $name,
            $this->formatNumber($vix),
            $priceDate,
            $this->formatNumber($threshold),
        );
    }

    protected function formatNumber(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
