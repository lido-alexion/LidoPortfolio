<?php

namespace App\Services\Execution;

use Carbon\CarbonImmutable;

/**
 * Strict evidence contract for a future Kite order-margin adapter.
 *
 * This policy is deliberately not wired into live execution. Kite's published
 * response schema does not provide a provider timestamp, so the current
 * gateway cannot produce evidence that passes this contract.
 */
final class KiteOrderMarginEvidencePolicy
{
    /** @param array<string,mixed> $evidence @param array<string,mixed> $order @param array<string,mixed> $quote */
    public function assess(
        array $evidence,
        string $expectedAccount,
        array $order,
        array $quote,
        CarbonImmutable $now,
        int $maxAgeSeconds,
        float $maxExposure,
        float $maxQuoteDeviationPct,
    ): array {
        if (strtoupper((string) ($order['side'] ?? '')) !== 'BUY') return $this->blocked('unsupported_side');
        if (strtoupper((string) ($order['order_type'] ?? '')) !== 'LIMIT') return $this->blocked('unbounded_order_type');
        foreach (['symbol', 'exchange', 'product', 'variety'] as $field) {
            if (! is_string($order[$field] ?? null) || trim($order[$field]) === '') {
                return $this->blocked('order_context_invalid');
            }
        }
        $quantity = $order['quantity'] ?? null;
        $limitPrice = $order['limit_price'] ?? null;
        if (! is_numeric($quantity) || ! is_finite((float) $quantity) || (float) $quantity <= 0
            || ! is_numeric($limitPrice) || ! is_finite((float) $limitPrice) || (float) $limitPrice <= 0) {
            return $this->blocked('invalid_order_limits');
        }

        if (($evidence['status'] ?? null) !== 'success') return $this->blocked('broker_evidence_unavailable');
        if (($evidence['account_identity_verified'] ?? false) !== true
            || $expectedAccount === ''
            || ! is_string($evidence['provider_account_id'] ?? null)
            || ! hash_equals($expectedAccount, $evidence['provider_account_id'])) {
            return $this->blocked('wrong_or_unverified_broker_account');
        }
        if (($evidence['provider_timestamp_verified'] ?? false) !== true) return $this->blocked('provider_timestamp_unverified');
        $providerAt = $this->timestamp($evidence['provider_timestamp'] ?? null);
        $requestStartedAt = $this->timestamp($evidence['request_started_at'] ?? null);
        $receivedAt = $this->timestamp($evidence['response_received_at'] ?? null);
        if (! $providerAt || ! $requestStartedAt || ! $receivedAt || $maxAgeSeconds < 1) return $this->blocked('evidence_timestamp_invalid');
        $age = $providerAt->diffInSeconds($now, false);
        $localAge = $receivedAt->diffInSeconds($now, false);
        $captureWindow = $requestStartedAt->diffInSeconds($receivedAt, false);
        if ($age < 0 || $age > $maxAgeSeconds || $localAge < 0 || $localAge > $maxAgeSeconds
            || $captureWindow < 0 || $captureWindow > $maxAgeSeconds) {
            return $this->blocked('broker_evidence_stale');
        }

        if (($evidence['order_margin_verified'] ?? false) !== true) return $this->blocked('order_margin_unverified');
        if (($evidence['request_matches_order'] ?? false) !== true) return $this->blocked('order_margin_request_mismatch');
        $fingerprint = $this->candidateFingerprint($order);
        if (! is_string($evidence['candidate_order_hash'] ?? null)
            || ! hash_equals($fingerprint, $evidence['candidate_order_hash'])) {
            return $this->blocked('order_margin_request_mismatch');
        }
        if (($evidence['open_orders_included'] ?? false) !== true) return $this->blocked('open_order_reservations_unproven');
        $pendingOrderCount = $evidence['pending_order_count'] ?? null;
        $marginContextOrderCount = $evidence['margin_context_order_count'] ?? null;
        if (! is_int($pendingOrderCount) || $pendingOrderCount < 0
            || ! is_int($marginContextOrderCount) || $marginContextOrderCount !== $pendingOrderCount) {
            return $this->blocked('open_order_snapshot_incomplete');
        }
        if (($evidence['positions_included'] ?? false) !== true) return $this->blocked('positions_unproven');
        if (($evidence['required_cash_semantics_verified'] ?? false) !== true) return $this->blocked('required_cash_semantics_unverified');

        $requiredCash = $this->finiteNonNegative($evidence['required_cash'] ?? null);
        $charges = $this->finiteNonNegative($evidence['charges'] ?? null);
        $availableCash = $this->finiteNonNegative($evidence['available_cash'] ?? null);
        // Collateral is recorded separately for audit and is never silently
        // added to cash in this conservative buy authorization contract.
        $collateral = $this->finiteNonNegative($evidence['available_collateral'] ?? null);
        if ($requiredCash === null || $charges === null || $availableCash === null || $collateral === null) {
            return $this->blocked('broker_margin_values_invalid');
        }

        if (($quote['provider_timestamp_verified'] ?? false) !== true) return $this->blocked('quote_timestamp_unverified');
        $quoteAt = $this->timestamp($quote['provider_timestamp'] ?? null);
        $quotePrice = $this->finitePositive($quote['price'] ?? null);
        $maxDeviation = is_finite($maxQuoteDeviationPct) && $maxQuoteDeviationPct >= 0 ? $maxQuoteDeviationPct : null;
        if (! $quoteAt || ! $quotePrice || $maxDeviation === null || $maxExposure <= 0 || ! is_finite($maxExposure)) {
            return $this->blocked('price_bound_unavailable');
        }
        $quoteAge = $quoteAt->diffInSeconds($now, false);
        if ($quoteAge < 0 || $quoteAge > $maxAgeSeconds) return $this->blocked('quote_stale');
        $authorizedPrice = $quotePrice * (1 + ($maxDeviation / 100));
        $grossExposure = (float) $quantity * (float) $limitPrice;
        if (! is_finite($authorizedPrice) || ! is_finite($grossExposure)
            || (float) $limitPrice > $authorizedPrice || $grossExposure > $maxExposure) {
            return $this->blocked('price_or_exposure_bound_exceeded');
        }

        // The order-margin endpoint is documented to account for existing
        // open orders. Do not subtract local reservations a second time.
        $required = $requiredCash + $charges;
        if (! is_finite($required)) return $this->blocked('broker_margin_values_invalid');
        return [
            'allowed' => $availableCash >= $required,
            'reason' => $availableCash >= $required ? null : 'insufficient_broker_cash',
            'required_cash' => $requiredCash,
            'charges' => $charges,
            'required_total' => $required,
            'available_cash' => $availableCash,
            'available_collateral' => $collateral,
            'gross_exposure' => $grossExposure,
            'existing_open_orders_included' => true,
            'local_reservations_subtracted' => false,
        ];
    }

    /** Stable hash of the exact order fields used by the evidence contract. */
    public function candidateFingerprint(array $order): string
    {
        $canonical = [
            'symbol' => strtoupper(trim((string) ($order['symbol'] ?? ''))),
            'exchange' => strtoupper(trim((string) ($order['exchange'] ?? ''))),
            'side' => strtoupper(trim((string) ($order['side'] ?? ''))),
            'product' => strtoupper(trim((string) ($order['product'] ?? ''))),
            'variety' => strtoupper(trim((string) ($order['variety'] ?? ''))),
            'order_type' => strtoupper(trim((string) ($order['order_type'] ?? ''))),
            'quantity' => $order['quantity'] ?? null,
            'limit_price' => $order['limit_price'] ?? null,
        ];
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') return null;
        try { return CarbonImmutable::parse($value); } catch (\Throwable) { return null; }
    }

    private function finiteNonNegative(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }

    private function finitePositive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }

    private function blocked(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
