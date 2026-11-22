<?php

namespace App\Services\Execution;

use Carbon\CarbonImmutable;

/**
 * Fail-closed policy for a single live buy. Equity buying power is broker
 * capacity only; it is never StoX ledger cash or reconciliation evidence.
 */
final class KitePerOrderBuyingPowerPolicy
{
    /** @param array<string,mixed> $evidence */
    public function assess(array $evidence, string $expectedAccount, string $side, string $orderType, float $quantity, ?float $limitPrice, CarbonImmutable $now, int $maxAgeSeconds, float $chargeBufferPct): array
    {
        if (strtoupper($side) !== 'BUY') return ['allowed' => true, 'reason' => null];
        if (strtoupper($orderType) !== 'LIMIT') return $this->blocked('unsupported_order_type');
        if ($quantity <= 0 || $limitPrice === null || ! is_finite($limitPrice) || $limitPrice <= 0) return $this->blocked('invalid_order_limits');
        // A local connection record is not proof that a particular provider
        // response belongs to that account, nor that the response is fresh.
        // Require explicit evidence provenance before any affordability math.
        if (($evidence['account_identity_verified'] ?? false) !== true) return $this->blocked('broker_account_identity_unverified');
        if (($evidence['provider_timestamp_verified'] ?? false) !== true) return $this->blocked('broker_funds_timestamp_unverified');
        if (($evidence['order_margin_verified'] ?? false) !== true) return $this->blocked('broker_order_margin_unverified');
        if ($expectedAccount === '' || ! isset($evidence['account_id']) || ! hash_equals($expectedAccount, (string) $evidence['account_id'])) return $this->blocked('wrong_broker_account');
        if (($evidence['status'] ?? null) !== 'success' || ! is_numeric($evidence['available_equity'] ?? null) || ! is_finite((float) $evidence['available_equity'])) return $this->blocked('broker_funds_unavailable');
        try { $capturedAt = CarbonImmutable::parse((string) ($evidence['captured_at'] ?? '')); } catch (\Throwable) { return $this->blocked('broker_funds_timestamp_invalid'); }
        $age = $capturedAt->diffInSeconds($now, false);
        if ($age < 0 || $age > $maxAgeSeconds) return $this->blocked('broker_funds_stale');
        if ($chargeBufferPct < 0 || ! is_finite($chargeBufferPct)) return $this->blocked('charge_buffer_invalid');

        $gross = round($quantity * $limitPrice, 2);
        $chargesBuffer = round($gross * ($chargeBufferPct / 100), 2);
        $required = $gross + $chargesBuffer;
        $available = max(0.0, (float) $evidence['available_equity']);
        return [
            'allowed' => $available >= $required,
            'reason' => $available >= $required ? null : 'insufficient_broker_buying_power',
            'available_equity_buying_power' => $available,
            'gross_required' => $gross,
            'charges_buffer' => $chargesBuffer,
            'required_total' => $required,
            'cash_semantics' => 'broker_equity_buying_power_not_stox_accounting_cash',
        ];
    }

    private function blocked(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
