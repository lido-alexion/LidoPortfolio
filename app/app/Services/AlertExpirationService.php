<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;

class AlertExpirationService
{
    public const REASON_MANUAL_ALL = 'manual_all';

    public const REASON_ACKNOWLEDGED = 'acknowledged';

    public const REASON_MAX_AGE = 'max_age_100h';

    public const REASON_DATA_REFRESH = 'data_refresh';

    public const REASON_HOLDING_CLOSED = 'holding_closed';

    public function expireAlert(Alert $alert, string $reason): bool
    {
        if ($alert->expired_at !== null) {
            return false;
        }

        $alert->update([
            'expired_at' => now(),
            'expiration_reason' => $reason,
        ]);

        return true;
    }

    public function expireAllForProfile(PortfolioProfile $profile): int
    {
        return Alert::query()
            ->where('profile_id', $profile->id)
            ->whereNull('expired_at')
            ->update([
                'expired_at' => now(),
                'expiration_reason' => self::REASON_MANUAL_ALL,
            ]);
    }

    public function acknowledgeForProfile(PortfolioProfile $profile, Alert $alert): bool
    {
        if ((int) $alert->profile_id !== (int) $profile->id) {
            return false;
        }

        if (! $this->profileHoldsStock($profile, (int) $alert->stock_id)) {
            return false;
        }

        return $this->expireAlert($alert, self::REASON_ACKNOWLEDGED);
    }

    public function expireOlderThanHours(int $hours): int
    {
        $cutoff = now()->subHours($hours);

        return Alert::query()
            ->whereNull('expired_at')
            ->where('created_at', '<', $cutoff)
            ->update([
                'expired_at' => now(),
                'expiration_reason' => self::REASON_MAX_AGE,
            ]);
    }

    /**
     * Expire active alerts created before the new trading session date.
     */
    public function expireBeforeTradingDay(Carbon $tradingDay): int
    {
        $cutoff = $tradingDay->copy()->startOfDay();

        return Alert::query()
            ->whereNull('expired_at')
            ->where('created_at', '<', $cutoff)
            ->update([
                'expired_at' => now(),
                'expiration_reason' => self::REASON_DATA_REFRESH,
            ]);
    }

    /**
     * Expire active alerts for a profile/stock after the ledger reaches zero quantity.
     *
     * Called from holdings replay when quantity hits zero. Do not consult the holdings
     * table here — that row is updated only after replay finishes, so a stillHeld check
     * would incorrectly skip expiration for already-open positions.
     */
    public function expireForProfileStockIfUnheld(PortfolioProfile $profile, Stock $stock): int
    {
        return Alert::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->whereNull('expired_at')
            ->update([
                'expired_at' => now(),
                'expiration_reason' => self::REASON_HOLDING_CLOSED,
            ]);
    }

    public function profileHoldsStock(PortfolioProfile $profile, int $stockId): bool
    {
        return Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stockId)
            ->where('quantity', '>', 0)
            ->exists();
    }

    public function latestPortfolioPriceDate(): ?string
    {
        $stockIds = Holding::query()
            ->where('quantity', '>', 0)
            ->distinct()
            ->pluck('stock_id');

        $query = StockPrice::query();
        if ($stockIds->isNotEmpty()) {
            $query->whereIn('stock_id', $stockIds);
        }

        return $query->max('price_date');
    }
}
