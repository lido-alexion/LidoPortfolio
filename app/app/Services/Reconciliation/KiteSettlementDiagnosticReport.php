<?php

namespace App\Services\Reconciliation;

/**
 * Pure, read-only diagnostics for a single StoX profile, Kite account and day.
 * Inputs are already-loaded evidence; this class deliberately performs no I/O.
 */
final class KiteSettlementDiagnosticReport
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly KiteSettlementEvidencePreparation $preparation = new KiteSettlementEvidencePreparation(),
        private readonly KiteSettlementHoldingsEvidence $holdingsEvidence = new KiteSettlementHoldingsEvidence(),
    ) {}

    /**
     * @param list<array<string,mixed>> $portfolioSnapshots
     * @param list<array<string,mixed>> $tradeImports
     * @param list<array<string,mixed>> $syncRuns
     * @param list<array<string,mixed>> $fundsSnapshots
     * @param list<array<string,mixed>>|null $stoxHoldings
     * @param list<array<string,mixed>> $preparedImports
     * @param array<string,mixed>|null $brokerSnapshot A supplied capture candidate, never independent proof.
     * @param array<string,mixed>|null $accountBinding Explicit trusted mapping of profile to broker user ID.
     * @return array<string,mixed>
     */
    public function build(
        int $profileId,
        string $brokerAccountId,
        string $tradingDay,
        ?array $accountBinding,
        array $portfolioSnapshots = [],
        array $tradeImports = [],
        array $syncRuns = [],
        array $fundsSnapshots = [],
        ?array $stoxHoldings = null,
        ?array $brokerSnapshot = null,
        array $preparedImports = [],
        array $historicalCoverage = [],
        ?int $stoxHoldingsProfileId = null,
    ): array {
        if ($profileId < 1 || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $brokerAccountId) !== 1 || ! $this->validDate($tradingDay)) {
            throw new \InvalidArgumentException('A positive profile, valid broker account ID and valid trading day are required.');
        }
        foreach ([$portfolioSnapshots, $tradeImports, $syncRuns, $fundsSnapshots, $preparedImports] as $rows) {
            if (! array_is_list($rows) || count($rows) > self::MAX_ROWS) {
                throw new \InvalidArgumentException('Diagnostic evidence must be a bounded list of at most 5000 rows.');
            }
        }
        if (count($historicalCoverage) > 20) {
            throw new \InvalidArgumentException('Historical coverage metadata must be bounded.');
        }
        if ($stoxHoldings !== null && (! array_is_list($stoxHoldings) || count($stoxHoldings) > self::MAX_ROWS)) {
            throw new \InvalidArgumentException('StoX holdings must be a bounded list of at most 5000 rows.');
        }

        $boundProfile = $accountBinding['profile_id'] ?? null;
        $accountVerified = is_array($accountBinding)
            && $this->sameId($boundProfile, $profileId)
            && is_string($accountBinding['broker_account_id'] ?? null)
            && hash_equals($brokerAccountId, $accountBinding['broker_account_id'])
            && ($accountBinding['verified'] ?? false) === true;
        $diagnostics = [];
        if (! $accountVerified) {
            $diagnostics[] = 'profile_to_broker_account_binding_unverified';
        }

        $portfolio = $this->exactRows($portfolioSnapshots, $profileId, null, $tradingDay, 'snapshot_date');
        $profileTrades = $this->exactRows($tradeImports, $profileId, null, $tradingDay, 'executed_at');
        $trades = array_values(array_filter($profileTrades, fn ($row) => ! array_key_exists('broker_account_id', $row)
            || (is_string($row['broker_account_id']) && hash_equals($brokerAccountId, $row['broker_account_id']))));
        $foreignTrades = count($profileTrades) - count($trades);
        $runs = $this->exactRows($syncRuns, $profileId, null, $tradingDay, 'trading_date');
        $funds = $this->exactRows($fundsSnapshots, $profileId, $brokerAccountId, $tradingDay, 'trading_date');
        $foreignFunds = array_values(array_filter($fundsSnapshots, fn ($row) => is_array($row)
            && $this->sameId($row['profile_id'] ?? null, $profileId) && ($row['trading_date'] ?? null) === $tradingDay
            && isset($row['broker_account_id']) && (string) $row['broker_account_id'] !== $brokerAccountId));

        if ($portfolio === []) {
            $diagnostics[] = 'portfolio_snapshot_missing_for_profile_day';
        } else {
            $diagnostics[] = 'portfolio_snapshot_is_profile_level_not_broker_account_evidence';
        }
        if ($trades === []) {
            $diagnostics[] = 'cnc_imports_missing_for_profile_day';
        } else {
            $diagnostics[] = 'cnc_imports_are_profile_attributed_without_verified_account_provenance';
            $diagnostics[] = 'historical_cnc_account_attribution_unavailable';
            if (count(array_filter($trades, static fn ($row) => ! is_numeric($row['stock_id'] ?? null)
                || trim((string) ($row['symbol'] ?? '')) === '' || trim((string) ($row['exchange'] ?? '')) === '')) > 0) {
                $diagnostics[] = 'some_cnc_imports_cannot_map_to_portfolio_instrument';
            }
            if (count(array_filter($trades, static fn ($row) => ! in_array($row['status'] ?? null,
                ['imported', 'matched_manual', 'matched_existing'], true))) > 0) {
                $diagnostics[] = 'some_cnc_imports_are_review_only_or_untrusted';
            }
        }
        if ($this->hasMalformedOrOutOfScope($portfolioSnapshots, $profileId, 'snapshot_date', $tradingDay)
            || $this->hasMalformedOrOutOfScope($tradeImports, $profileId, 'executed_at', $tradingDay)
            || $this->hasMalformedOrOutOfScope($syncRuns, $profileId, 'trading_date', $tradingDay)) {
            $diagnostics[] = 'profile_level_evidence_contains_malformed_or_unverified_rows';
        }
        if (count(array_filter($trades, static fn ($row) => ! array_key_exists('broker_account_id', $row))) > 0) {
            $diagnostics[] = 'legacy_or_unbound_cnc_imports_not_account_evidence';
        }
        if ($foreignTrades > 0) $diagnostics[] = 'other_broker_account_cnc_imports_excluded';
        if ($runs === []) {
            $diagnostics[] = 'cnc_sync_run_missing_for_profile_day';
        } elseif (! $this->hasCompleteRun($runs, $tradingDay)) {
            $diagnostics[] = 'cnc_sync_incomplete_or_needs_review';
        }
        if ($foreignFunds !== []) {
            $diagnostics[] = 'other_broker_account_funds_evidence_excluded';
        }
        if ($funds === []) {
            $diagnostics[] = 'broker_funds_snapshot_missing_for_account_day';
        } else {
            $diagnostics[] = 'funds_snapshot_is_aggregate_and_not_holdings_settlement_proof';
            foreach ($funds as $fund) {
                if (($fund['freshness'] ?? null) !== 'fresh'
                    || ! $this->dateFieldMatches($fund['captured_at'] ?? null, $tradingDay, false)) {
                    $diagnostics[] = 'broker_funds_snapshot_stale_or_uncertain';
                }
                if (! $this->dateFieldMatches($fund['captured_at'] ?? null, $tradingDay, false)) {
                    $diagnostics[] = 'broker_funds_capture_timestamp_missing_or_out_of_scope';
                }
                if (($fund['payout_semantics_verified'] ?? false) !== true) {
                    $diagnostics[] = 'broker_payout_semantics_unverified';
                }
            }
        }
        if ($brokerSnapshot === null) {
            $diagnostics[] = 'persisted_broker_holdings_positions_capture_unavailable';
        }
        if ($stoxHoldings === null || ! $this->sameId($stoxHoldingsProfileId, $profileId)) {
            $diagnostics[] = 'true_profile_holdings_source_missing';
            $stoxHoldings = null;
        } elseif ($this->hasUnscopedHoldings($stoxHoldings, $profileId, $brokerAccountId)) {
            $diagnostics[] = 'stox_holdings_profile_provenance_missing_or_mismatched';
            $stoxHoldings = null;
        }

        $prepared = null;
        $comparison = null;
        if ($accountVerified && $brokerSnapshot !== null && $stoxHoldings !== null) {
            try {
                $prepared = $this->preparation->prepare($profileId, $brokerAccountId, $tradingDay, $stoxHoldings ?? [],
                    $brokerSnapshot, $preparedImports, $historicalCoverage);
                $diagnostics = [...$diagnostics, ...$prepared['diagnostics']];
                $comparison = $this->holdingsEvidence->compare($profileId, $tradingDay,
                    $prepared['stox_holdings'], $prepared['broker_snapshot'], $prepared['persisted_trades'],
                    $prepared['trade_evidence_complete']);
            } catch (\InvalidArgumentException) {
                $diagnostics[] = 'supplied_evidence_rejected_by_fail_closed_mapper';
            }
        } elseif ($brokerSnapshot !== null) {
            $diagnostics[] = 'capture_receipt_not_used_without_verified_profile_account_binding';
        }

        // Capture receipts are lineage metadata. Even a valid receipt does not
        // constitute independent settlement confirmation or broker statement data.
        $diagnostics[] = 'no_independent_broker_statement_or_settlement_confirmation';

        return [
            'profile_id' => $profileId,
            'broker_account_id' => $brokerAccountId,
            'trading_day' => $tradingDay,
            'account_binding' => $accountVerified ? 'verified_explicit_mapping' : 'unverified',
            'status' => 'diagnostic_only_review_required',
            'evidence' => [
                'portfolio_snapshots' => ['count' => count($portfolio), 'attribution' => 'profile_only_not_broker_account'],
                'cnc_imports' => ['count' => count($trades), 'attribution' => 'profile_only_account_provenance_unverified',
                    'historical_account_classifiable' => false,
                    'foreign_account_rows_excluded' => $foreignTrades,
                    'unmappable_instrument_count' => count(array_filter($trades, static fn ($row) => ! is_numeric($row['stock_id'] ?? null)
                        || trim((string) ($row['symbol'] ?? '')) === '' || trim((string) ($row['exchange'] ?? '')) === '')),
                    'review_only_count' => count(array_filter($trades, static fn ($row) => ! in_array($row['status'] ?? null,
                        ['imported', 'matched_manual', 'matched_existing'], true)))],
                'sync_runs' => ['count' => count($runs), 'complete' => $this->hasCompleteRun($runs, $tradingDay)],
                'funds_snapshots' => ['count' => count($funds), 'account_scoped' => true, 'settlement_proof' => false],
                'foreign_account_funds_excluded' => count($foreignFunds),
                'broker_holdings_capture_supplied' => $brokerSnapshot !== null,
                'independent_settlement_data_available' => false,
            ],
            'prepared_evidence' => $prepared,
            'holdings_diagnostic' => $comparison,
            'diagnostics' => array_values(array_unique($diagnostics)),
            'claim' => 'No actual brokerage settlement match is asserted; this report only identifies evidence scope and classification limits.',
            'policy' => 'read_only_no_persistence_no_broker_calls_no_adjustments_no_execution_gate',
        ];
    }

    private function exactRows(array $rows, int $profileId, ?string $accountId, string $day, string $dateField): array
    {
        return array_values(array_filter($rows, function ($row) use ($profileId, $accountId, $day, $dateField): bool {
            if (! is_array($row) || ! $this->sameId($row['profile_id'] ?? null, $profileId)) {
                return false;
            }
            if ($accountId !== null && (! isset($row['broker_account_id']) || ! is_string($row['broker_account_id'])
                || ! hash_equals($accountId, $row['broker_account_id']))) {
                return false;
            }
            return $this->dateFieldMatches($row[$dateField] ?? null, $day, $dateField !== 'executed_at');
        }));
    }

    private function hasCompleteRun(array $runs, string $tradingDay): bool
    {
        if ($runs === []) return false;
        foreach ($runs as $run) {
            if (! is_array($run) || ! $this->dateFieldMatches($run['started_at'] ?? null, $tradingDay, false)
                || ! $this->validTimestamp($run['completed_at'] ?? null)) return false;
        }
        usort($runs, fn ($a, $b) => $this->timestamp($b['started_at']) <=> $this->timestamp($a['started_at']));
        $latest = $runs[0];
        return ($latest['status'] ?? null) === 'completed'
            && $this->nonnegativeInteger($latest['review_count'] ?? null)
            && (int) $latest['review_count'] === 0
            && $this->timestamp($latest['completed_at']) >= $this->timestamp($latest['started_at']);
    }

    private function sameId(mixed $value, int $expected): bool
    {
        return (is_int($value) && $value === $expected)
            || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && $value === (string) $expected);
    }

    private function dateFieldMatches(mixed $value, string $day, bool $dateOnly): bool
    {
        if (! is_string($value) || strlen($value) > 64) return false;
        if ($dateOnly) return $value === $day && $this->validDate($value);
        if (! $this->validTimestamp($value)) return false;
        return $this->timestamp($value)->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d') === $day;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 64
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:?\\d{2})?$/D', $value) !== 1) return false;
        try { $dt = new \DateTimeImmutable($value, new \DateTimeZone('Asia/Kolkata')); $errors = \DateTimeImmutable::getLastErrors();
            return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $dt->format('Y-m-d') !== '0000-00-00';
        } catch (\Throwable) { return false; }
    }

    private function timestamp(string $value): \DateTimeImmutable { return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Kolkata')); }
    private function nonnegativeInteger(mixed $value): bool { return (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1); }

    private function hasMalformedOrOutOfScope(array $rows, int $profileId, string $field, string $day): bool
    {
        foreach ($rows as $row) {
            if (! is_array($row)) return true;
            $rowProfile = $row['profile_id'] ?? null;
            if (! $this->sameId($rowProfile, $profileId)) {
                if (! $this->validProfileIdShape($rowProfile)) return true;
                continue;
            }
            if (! $this->dateFieldMatches($row[$field] ?? null, $day, $field !== 'executed_at')) return true;
        }
        return false;
    }

    private function validProfileIdShape(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1);
    }

    private function hasUnscopedHoldings(array $rows, int $profileId, string $brokerAccountId): bool
    {
        foreach ($rows as $row) if (! is_array($row) || ! $this->sameId($row['profile_id'] ?? null, $profileId)
            || (array_key_exists('broker_account_id', $row) && (! is_string($row['broker_account_id'])
                || ! hash_equals($brokerAccountId, $row['broker_account_id'])))) return true;
        return false;
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
