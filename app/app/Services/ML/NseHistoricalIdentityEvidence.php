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
            if (array_key_exists('transition_ids', $identity)) {
                if (! $this->validChain($identity)) {
                    return null;
                }
                $matches[] = $identity['canonical_isin'];

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

    private function validChain(array $identity): bool
    {
        $ids = $identity['transition_ids'];
        if (! is_array($ids) || $ids === []
            || ! $this->validDate($identity['valid_from'] ?? '')
            || ! $this->validDate($identity['valid_until'] ?? '')
            || $identity['valid_from'] > $identity['valid_until']) {
            return false;
        }
        $observations = array_values(array_filter($identity['evidence'] ?? [], fn ($e) => ($e['role'] ?? '') === 'historical_observation' && $this->validEvidence($e)
            && $e['date'] === $identity['valid_from']
            && ($e['isin'] ?? '') === $identity['historical_isin']
            && ($e['symbol'] ?? '') === $identity['symbol']));
        if (count($observations) !== 1) {
            return false;
        }
        $current = $identity['historical_isin'];
        $seen = [$current];
        $previousDate = $identity['valid_until'];
        foreach ($ids as $index => $id) {
            $event = $this->document()['transitions'][$id] ?? [];
            $date = $event['effective_on'] ?? '';
            if (($event['action'] ?? '') !== 'subdivision'
                || ($event['symbol'] ?? '') !== $identity['symbol']
                || ($event['old_isin'] ?? '') !== $current
                || ! preg_match('/\AINE[A-Z0-9]{9}\z/', $event['new_isin'] ?? '')
                || in_array($event['new_isin'], $seen, true)
                || ! $this->validDate($date) || $date <= $previousDate
                || ($index === 0 && (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d') !== $identity['valid_until'])
                || ! $this->validEventEvidence($event)) {
                return false;
            }
            $current = $event['new_isin'];
            $seen[] = $current;
            $previousDate = $date;
        }

        return $current === ($identity['canonical_isin'] ?? null);
    }

    private function validEventEvidence(array $event): bool
    {
        $proof = [];
        foreach ($event['evidence'] ?? [] as $evidence) {
            if (! $this->validEvidence($evidence) || isset($proof[$evidence['role'] ?? ''])) {
                return false;
            }
            $proof[$evidence['role'] ?? ''] = $evidence;
        }
        foreach (['old_isin_event' => 'old_isin', 'new_isin_event' => 'new_isin'] as $role => $field) {
            $evidence = $proof[$role] ?? [];
            if (($evidence['isin'] ?? '') !== $event[$field]) {
                return false;
            }
            if (($evidence['effective_on'] ?? '') !== $event['effective_on']) {
                $revision = $proof['effective_date_revision'] ?? [];
                if (($revision['amends'] ?? '') !== ($evidence['document'] ?? null)
                    || ($revision['from_date'] ?? '') !== ($evidence['effective_on'] ?? null)
                    || ($revision['to_date'] ?? '') !== $event['effective_on']
                    || $revision['date'] < $evidence['date']) {
                    return false;
                }
            }
        }

        return true;
    }

    private function validEvidence(array $evidence): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $evidence['sha256'] ?? '')
            && parse_url($evidence['url'] ?? '', PHP_URL_SCHEME) === 'https'
            && in_array(parse_url($evidence['url'], PHP_URL_HOST), ['archives.nseindia.com', 'nsearchives.nseindia.com'], true)
            && $this->validDate($evidence['date'] ?? '');
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
