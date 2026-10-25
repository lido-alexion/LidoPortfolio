<?php

namespace App\Services\Bhavcopy;

use App\Services\ML\NseHistoricalIdentityEvidence;

/** Reuses only the pure lineage validator; ML policy and membership stay separate. */
class NsePriceIdentityEvidence extends NseHistoricalIdentityEvidence
{
    public function document(): array
    {
        return config('universe_nse_price_identities', []);
    }
}
