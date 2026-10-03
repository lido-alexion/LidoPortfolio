<?php

namespace App\Services\ML;

/** Reviewed security continuity only; this does not supply membership or prices. */
class NseHistoricalIdentityEvidence
{
    public function document(): array
    {
        return config('ml_nse_historical_identities');
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->document(), JSON_THROW_ON_ERROR));
    }

    public function canonicalIsin(array $member, string $date): ?string
    {
        $matches = [];
        foreach ($this->document()['identities'] ?? [] as $identity) {
            if (($identity['historical_isin'] ?? null) !== ($member['isin'] ?? null)
                || ($identity['symbol'] ?? null) !== ($member['symbol'] ?? null)
                || $date < ($identity['valid_from'] ?? '9999-12-31')
                || $date > ($identity['valid_until'] ?? '0000-01-01')) {
                continue;
            }
            // Incomplete provenance cannot authorize a conflicting-ISIN mapping.
            $roles = [];
            foreach ($identity['evidence'] ?? [] as $evidence) {
                if (preg_match('/\A[a-f0-9]{64}\z/', $evidence['sha256'] ?? '')
                    && parse_url($evidence['url'] ?? '', PHP_URL_SCHEME) === 'https'
                    && in_array(parse_url($evidence['url'], PHP_URL_HOST), ['archives.nseindia.com', 'nsearchives.nseindia.com'], true)
                    && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $evidence['date'] ?? '')) {
                    $roles[] = $evidence['role'];
                }
            }
            if (array_diff(['historical_observation', 'effective_date', 'isin_continuity'], $roles) !== []
                || ($identity['action'] ?? null) !== 'subdivision'
                || ($identity['change_effective_on'] ?? '') <= $identity['valid_until']
                || ! preg_match('/\AINE[A-Z0-9]{9}\z/', $identity['canonical_isin'] ?? '')) {
                return null;
            }
            $matches[] = $identity['canonical_isin'];
        }

        // Even duplicate evidence intervals require review, not arbitrary selection.
        return count($matches) === 1 ? $matches[0] : null;
    }
}
