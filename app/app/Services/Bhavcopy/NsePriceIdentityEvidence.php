<?php

namespace App\Services\Bhavcopy;

use App\Models\DataQualityIssue;
use App\Models\Stock;
use App\Services\ML\NseHistoricalIdentityEvidence;

/** Reuses only the pure lineage validator; ML policy and membership stay separate. */
class NsePriceIdentityEvidence extends NseHistoricalIdentityEvidence
{
    public function document(): array
    {
        return config('universe_nse_price_identities', []);
    }

    public function canonicalIsin(array $member, string $date): ?string
    {
        $symbol = strtoupper(trim((string) ($member['symbol'] ?? '')));
        $isin = strtoupper(trim((string) ($member['isin'] ?? '')));
        $databaseAvailable = false;
        try {
            $databaseAvailable = \Illuminate\Support\Facades\Schema::hasTable('portfolio_stocks')
                && \Illuminate\Support\Facades\Schema::hasTable('portfolio_data_quality_issues');
        } catch (\Throwable) {
            // The pure resolver is also used by config and matcher unit tests
            // without a booted database connection.
        }
        if ($databaseAvailable && preg_match('/\AINE[A-Z0-9]{9}\z/', $isin) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
            $stock = Stock::query()->where('symbol', $symbol)->where('exchange', 'NSE')->first();
            if ($stock !== null && strtoupper(trim((string) $stock->isin)) !== $isin) {
                $transition = DataQualityIssue::query()
                    ->where('stock_id', $stock->id)->where('issue_type', DataQualityIssue::TYPE_CORPORATE_ACTION)
                    ->where('issue_status', DataQualityIssue::STATUS_ACCEPTED)->whereDate('ex_date', '>', $date)
                    ->whereHas('linkedResolution', function ($query) {
                        $query->whereNotNull('resolved_by')->where('resolution_status', DataQualityIssue::STATUS_ACCEPTED)
                            ->where('metadata->human_approval_required', true)->where('metadata->auto_resolved', false)
                            ->whereNotNull('metadata->manual_override_context->historical_isin');
                    })->with('linkedResolution')->orderByDesc('ex_date')->first();
                $context = $transition?->linkedResolution?->metadata['manual_override_context'] ?? [];
                $from = (string) ($context['historical_valid_from'] ?? '');
                if ($transition !== null && ($context['historical_isin'] ?? null) === $isin
                    && $date >= $from && $date < $transition->ex_date->toDateString()) {
                    return strtoupper(trim((string) $stock->isin));
                }
            }
        }

        return parent::canonicalIsin($member, $date);
    }
}
