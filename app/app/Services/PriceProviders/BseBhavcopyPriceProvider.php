<?php

namespace App\Services\PriceProviders;

use App\Contracts\PriceProviderInterface;
use App\Services\BseBhavcopyService;
use App\Support\TradingCalendar;
use Carbon\Carbon;

class BseBhavcopyPriceProvider implements PriceProviderInterface
{
    public function __construct(
        protected BseBhavcopyService $bhavcopy,
    ) {}

    public function getName(): string
    {
        return 'bse_bhavcopy';
    }

    public function fetchHistorical(string $symbol, Carbon $from, Carbon $to, ?string $providerSymbol = null): array
    {
        $maxDays = (int) config('portfolio.universe_price_sync.bse_bhavcopy_max_gap_calendar_days', 45);
        if ($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > $maxDays) {
            return [];
        }

        $symbolUpper = strtoupper(trim($symbol));
        $scripCode = trim((string) ($providerSymbol ?? ''));
        $rows = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if (! TradingCalendar::isEquitySessionDate($cursor)) {
                $cursor->addDay();
                continue;
            }

            try {
                $this->bhavcopy->eachEquityRowForDate($cursor, function (array $row) use ($symbolUpper, $scripCode, &$rows): void {
                    if (! $this->matchesStock($row, $symbolUpper, $scripCode)) {
                        return;
                    }

                    $rows[] = [
                        'price_date' => $row['price_date'],
                        'open_price' => $row['open_price'],
                        'high_price' => $row['high_price'],
                        'low_price' => $row['low_price'],
                        'close_price' => $row['close_price'],
                        'volume' => $row['volume'],
                    ];
                });
            } catch (\Throwable) {
                $cursor->addDay();
                continue;
            }

            $cursor->addDay();
        }

        return $rows;
    }

    public function fetchLatest(string $symbol): ?array
    {
        $to = TradingCalendar::lastRequiredPriceSession();
        $from = $to->copy()->subDays(10);
        $rows = $this->fetchHistorical($symbol, $from, $to);

        return $rows !== [] ? end($rows) : null;
    }

    /**
     * @param  array{
     *   scrip_code?: string,
     *   symbol?: string
     * }  $row
     */
    protected function matchesStock(array $row, string $symbolUpper, string $scripCode): bool
    {
        if ($scripCode !== '' && ($row['scrip_code'] ?? '') === $scripCode) {
            return true;
        }

        return $symbolUpper !== '' && strtoupper((string) ($row['symbol'] ?? '')) === $symbolUpper;
    }
}
