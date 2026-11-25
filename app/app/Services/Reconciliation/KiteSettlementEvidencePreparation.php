<?php

namespace App\Services\Reconciliation;

use App\Support\TradingCalendar;
use Carbon\Carbon;

/** Pure mapper for synthetic, already-retrieved shadow evidence. */
final class KiteSettlementEvidencePreparation
{
    private const MAX_ROWS = 5000;

    private const STATUSES = ['imported', 'matched_manual', 'matched_existing'];

    /**
     * Profile identity and Kite identity are deliberately separate. Source proof is a
     * structured capture receipt; this adapter does not authenticate or fetch it.
     */
    public function prepare(int $profileId, string $brokerUserId, string $asOf, array $stoxHoldings,
        ?array $brokerSnapshot, array $imports, array $historicalCoverage): array
    {
        if ($profileId < 1) {
            throw new \InvalidArgumentException('Profile ID must be a positive integer.');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $brokerUserId) !== 1) {
            throw new \InvalidArgumentException('Kite broker user ID is invalid.');
        }
        if (! $this->validDate($asOf)) {
            throw new \InvalidArgumentException('Invalid as-of date.');
        }
        $diagnostics = [];
        $snapshot = $this->mapSnapshot($profileId, $brokerUserId, $asOf, $brokerSnapshot, $diagnostics);
        [$trades, $complete] = $this->mapImports($profileId, $brokerUserId, $asOf, $imports, $historicalCoverage, $diagnostics);
        $stoxValid = $this->rowsBounded($stoxHoldings) && $this->validRows($stoxHoldings, 'holding');
        if (! $stoxValid) {
            $diagnostics[] = 'stox_holdings_invalid_or_unbounded';
        }

        return ['stox_holdings' => $stoxValid ? array_values(array_map(fn ($r) => $this->mapStoxHolding($r), $stoxHoldings)) : [], 'broker_snapshot' => $snapshot,
            'persisted_trades' => $trades, 'trade_evidence_complete' => $complete,
            'diagnostics' => array_values(array_unique($diagnostics))];
    }

    private function mapSnapshot(int $profileId, string $brokerUserId, string $asOf, ?array $source, array &$d): array
    {
        $empty = ['provider' => 'kite', 'account_id' => $profileId, 'broker_user_id' => null, 'captured_at' => null,
            'holdings_complete' => false, 'holdings' => null, 'positions' => ['complete' => false, 'net' => [], 'day' => []], 'cash_comparable' => false];
        if ($source === null) {
            $d[] = 'broker_snapshot_unavailable';

            return $empty;
        }
        $proof = $source['source_proof'] ?? null;
        $providerValid = ($source['provider'] ?? null) === 'kite';
        $accountValid = is_string($source['broker_user_id'] ?? null) && strlen($source['broker_user_id']) <= 128
            && hash_equals($brokerUserId, $source['broker_user_id']);
        $capturedAt = $source['captured_at'] ?? null;
        $proofValid = is_array($proof) && ($proof['provider'] ?? null) === 'kite'
            && is_string($proof['broker_user_id'] ?? null) && strlen($proof['broker_user_id']) <= 128
            && hash_equals($brokerUserId, $proof['broker_user_id'])
            && is_string($proof['capture_id'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $proof['capture_id']) === 1
            && ($proof['captured_at'] ?? null) === $capturedAt
            && ($proof['requested'] ?? null) === ['holdings', 'positions']
            && ($proof['succeeded'] ?? null) === ['holdings', 'positions'];
        if (! $providerValid) {
            $d[] = 'broker_provider_missing_or_wrong';
        }
        if (! $accountValid || ! $proofValid) {
            $d[] = 'broker_account_or_capture_proof_missing_or_mismatched';
        }
        if (! $this->timestampOnIstDate($capturedAt, $asOf)) {
            $d[] = 'broker_capture_timestamp_missing_or_out_of_scope';
        }
        $holdings = $source['holdings'] ?? null;
        $holdingsValid = is_array($holdings) && array_is_list($holdings) && $this->rowsBounded($holdings) && $this->validRows($holdings, 'holding');
        if (! $holdingsValid) {
            $d[] = 'broker_holdings_invalid_or_unbounded';
        }
        $positions = $source['positions'] ?? null;
        $positionsValid = is_array($positions) && isset($positions['net'], $positions['day'])
            && is_array($positions['net']) && array_is_list($positions['net']) && is_array($positions['day']) && array_is_list($positions['day'])
            && $this->rowsBounded($positions['net']) && $this->rowsBounded($positions['day'])
            && $this->validRows($positions['net'], 'position') && $this->validRows($positions['day'], 'position');
        if (! $positionsValid) {
            $d[] = 'broker_positions_invalid_or_missing_bucket';
        }
        $knownSession = TradingCalendar::isEquitySessionDate(Carbon::parse($asOf, 'Asia/Kolkata'));
        if (! $knownSession) {
            $d[] = 'broker_snapshot_session_unknown';
        }
        $complete = $providerValid && $accountValid && $proofValid && $knownSession && $this->timestampOnIstDate($capturedAt, $asOf) && $holdingsValid && $positionsValid;

        return ['provider' => 'kite', 'account_id' => $profileId, 'broker_user_id' => $accountValid ? $brokerUserId : null,
            'captured_at' => is_string($capturedAt) ? $capturedAt : null, 'holdings_complete' => $complete,
            'holdings' => $holdingsValid ? array_values(array_map(fn ($r) => $this->mapHolding($r), $holdings)) : null,
            'positions' => ['complete' => $complete, 'net' => $positionsValid ? array_values(array_map(fn ($r) => $this->mapPosition($r), $positions['net'])) : [],
                'day' => $positionsValid ? array_values(array_map(fn ($r) => $this->mapPosition($r), $positions['day'])) : []], 'cash_comparable' => false];
    }

    private function mapImports(int $profileId, string $brokerUserId, string $asOf, array $imports, array $coverage, array &$d): array
    {
        $bounded = $this->rowsBounded($imports);
        $coverageFrom = is_string($coverage['from'] ?? null) ? $coverage['from'] : '';
        $coverageValid = ($coverage['source'] ?? null) === 'persisted_historical_imports'
            && ($coverage['complete'] ?? false) === true && ($coverage['through'] ?? null) === $asOf
            && $this->validDate($coverageFrom) && $coverageFrom <= $asOf
            && ($coverage['active_account_broker_user_id'] ?? null) === $brokerUserId
            && ($coverage['source'] ?? '') !== 'kite_trades_api';
        $complete = $bounded && $coverageValid;
        if (! $bounded) {
            $d[] = 'persisted_trade_rows_unbounded';
        }
        if (! $coverageValid) {
            $d[] = 'historical_trade_coverage_missing_or_unverified';
        }
        $result = [];
        $ids = [];
        if (! $bounded) {
            return [[], false];
        }
        foreach ($imports as $row) {
            if (! is_array($row)) {
                $complete = false;
                $d[] = 'persisted_trade_row_malformed';

                continue;
            }
            if (! is_string($row['product'] ?? null)) {
                $complete = false;
                $d[] = 'persisted_trade_invalid';

                continue;
            }
            if (strtoupper($row['product']) !== 'CNC') {
                continue;
            }
            // Legacy rows have no broker account provenance; profile ownership does not prove it.
            if (! isset($row['broker_user_id']) || ! is_string($row['broker_user_id']) || ! hash_equals($brokerUserId, $row['broker_user_id'])
                || (! is_int($row['profile_id'] ?? null) && ! is_string($row['profile_id'] ?? null))
                || (string) $row['profile_id'] !== (string) $profileId) {
                $complete = false;
                $d[] = 'persisted_trade_broker_account_unresolved';

                continue;
            }
            if (! is_string($row['status'] ?? null) || ! in_array($row['status'], self::STATUSES, true)) {
                $complete = false;
                $d[] = 'persisted_trade_status_requires_review';

                continue;
            }
            if (! $this->validTrade($row)) {
                $complete = false;
                $d[] = 'persisted_trade_invalid';

                continue;
            }
            $id = (string) $row['broker_trade_id'];
            $fingerprint = json_encode(array_intersect_key($row, array_flip(['profile_id', 'broker_user_id', 'symbol', 'exchange', 'isin', 'stock_isin', 'side', 'quantity', 'price', 'executed_at', 'product', 'status'])));
            if (isset($ids[$id])) {
                $complete = false;
                $d[] = $ids[$id] === $fingerprint ? 'persisted_trade_duplicate_id' : 'persisted_trade_duplicate_id_conflict';

                continue;
            }
            $ids[$id] = $fingerprint;
            $executed = $this->instant($row['executed_at']);
            $day = $executed->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
            if ($day > $asOf) {
                continue;
            }
            if (! TradingCalendar::isEquitySessionDate(Carbon::parse($day, 'Asia/Kolkata'))) {
                $complete = false;
                $d[] = 'persisted_trade_execution_session_unknown';

                continue;
            }
            $settles = TradingCalendar::addSessions(Carbon::parse($day, 'Asia/Kolkata'), 1)->toDateString();
            if (! TradingCalendar::isEquitySessionDate(Carbon::parse($settles, 'Asia/Kolkata'))) {
                $complete = false;
                $d[] = 'settlement_session_unknown';

                continue;
            }
            $result[] = ['profile_id' => $profileId, 'broker_user_id' => $brokerUserId, 'broker_trade_id' => $id,
                'symbol' => strtoupper($row['symbol']), 'exchange' => strtoupper($row['exchange']),
                'isin' => $this->isin($row['isin'] ?? $row['stock_isin'] ?? null), 'side' => strtolower($row['side']),
                'quantity' => $row['quantity'], 'executed_at' => $row['executed_at'], 'settles_by' => $settles,
                'product' => 'CNC', 'status' => $row['status']];
        }

        return [$result, $complete];
    }

    private function validTrade(array $r): bool
    {
        return is_string($r['broker_trade_id'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $r['broker_trade_id']) === 1
            && $this->identity($r) && (! array_key_exists('stock_isin', $r) || $this->isin($r['stock_isin']))
            && (! array_key_exists('price', $r) || $this->positive($r['price']))
            && is_string($r['side'] ?? null) && in_array(strtolower($r['side']), ['buy', 'sell'], true)
            && $this->wholePositive($r['quantity'] ?? null) && $this->validInstant($r['executed_at'] ?? null)
            && is_string($r['product'] ?? null) && strtoupper($r['product']) === 'CNC';
    }

    private function validRows(array $rows, string $type): bool
    {
        foreach ($rows as $r) {
            if (! is_array($r) || ! $this->identity($r)) {
                return false;
            }
            $q = $r['quantity'] ?? $r['net_quantity'] ?? null;
            if ($type === 'position' ? ! $this->finite($q) : ! $this->positive($q)) {
                return false;
            }
            if ($type === 'holding' && (! $this->nonnegative($r['t1_quantity'] ?? 0) || ! $this->nonnegative($r['used_quantity'] ?? 0)
                || (isset($r['isin']) && ! $this->isin($r['isin'])))) {
                return false;
            }
        }

        return true;
    }

    private function identity(array $r): bool
    {
        $symbol = $r['symbol'] ?? $r['tradingsymbol'] ?? null;

        return is_string($symbol) && preg_match('/^[A-Z0-9][A-Z0-9._&-]{0,29}$/Di', $symbol) === 1
            && is_string($r['exchange'] ?? null) && in_array(strtoupper($r['exchange']), ['NSE', 'BSE'], true)
            && (! isset($r['isin']) || $this->isin($r['isin']));
    }

    private function mapHolding(array $r): array
    {
        return ['symbol' => strtoupper($r['symbol'] ?? $r['tradingsymbol']), 'exchange' => strtoupper($r['exchange']), 'isin' => $this->isin($r['isin'] ?? null), 'quantity' => $r['quantity'], 't1_quantity' => $r['t1_quantity'] ?? 0, 'used_quantity' => $r['used_quantity'] ?? 0];
    }

    private function mapStoxHolding(array $r): array
    {
        return ['symbol' => strtoupper($r['symbol'] ?? $r['tradingsymbol']), 'exchange' => strtoupper($r['exchange']), 'isin' => $this->isin($r['isin'] ?? null), 'quantity' => $r['quantity']];
    }

    private function mapPosition(array $r): array
    {
        return ['symbol' => strtoupper($r['symbol'] ?? $r['tradingsymbol']), 'exchange' => strtoupper($r['exchange']), 'isin' => $this->isin($r['isin'] ?? null), 'quantity' => $r['quantity'] ?? $r['net_quantity']];
    }

    private function isin(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[A-Z]{2}[A-Z0-9]{10}$/Di', $v) === 1 ? strtoupper($v) : null;
    }

    private function positive(mixed $v): bool
    {
        return $this->finite($v) && (float) $v > 0;
    }

    private function wholePositive(mixed $v): bool
    {
        return $this->positive($v) && floor((float) $v) === (float) $v;
    }

    private function nonnegative(mixed $v): bool
    {
        return $this->finite($v) && (float) $v >= 0;
    }

    private function finite(mixed $v): bool
    {
        return (is_int($v) || is_float($v) || (is_string($v) && strlen($v) <= 32 && is_numeric($v))) && is_finite((float) $v) && abs((float) $v) <= 1.0e12;
    }

    private function rowsBounded(array $v): bool
    {
        return array_is_list($v) && count($v) <= self::MAX_ROWS;
    }

    private function validDate(string $d): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);

        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    private function validInstant(mixed $v): bool
    {
        if (! is_string($v) || strlen($v) > 64 || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $v) !== 1) {
            return false;
        }
        try {
            $date = new \DateTimeImmutable($v, new \DateTimeZone('Asia/Kolkata'));
            $errors = \DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (\Throwable) {
            return false;
        }
    }

    private function instant(string $v): \DateTimeImmutable
    {
        return new \DateTimeImmutable($v, new \DateTimeZone('Asia/Kolkata'));
    }

    private function timestampOnIstDate(mixed $v, string $d): bool
    {
        if (! $this->validInstant($v)) {
            return false;
        }

        return $this->instant($v)->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d') === $d;
    }
}
