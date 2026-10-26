<?php

namespace App\Services;

/** Pure preview for filling absent identities, never lifecycle or price authority. */
class BseIdentityReconciliationPlan
{
    public function build(array $stocks, array $source): array
    {
        $stocks = array_map(fn ($stock) => [
            'id' => (int) $stock['id'],
            'symbol' => strtoupper(trim((string) ($stock['symbol'] ?? ''))),
            'exchange' => strtoupper(trim((string) ($stock['exchange'] ?? ''))),
            'isin' => $stock['isin'] ?? null,
            'bse_scrip_code' => trim((string) ($stock['bse_scrip_code'] ?? '')),
            'is_active' => (bool) ($stock['is_active'] ?? false),
            'is_benchmark' => (bool) ($stock['is_benchmark'] ?? false),
            'admin_deactivated' => (bool) ($stock['admin_deactivated'] ?? false),
        ], $stocks);
        usort($stocks, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        $source = array_map(fn ($row) => [
            'scrip_code' => trim((string) ($row['scrip_code'] ?? '')),
            'symbol' => strtoupper(trim((string) ($row['symbol'] ?? ''))),
            'isin' => strtoupper(trim((string) ($row['isin'] ?? ''))),
        ], $source);
        usort($source, fn ($a, $b) => [$a['scrip_code'], $a['symbol'], $a['isin']] <=> [$b['scrip_code'], $b['symbol'], $b['isin']]);
        $byCode = $sourceIsins = $masterCodes = $masterIsins = [];
        foreach ($source as $row) {
            $byCode[$row['scrip_code']][] = $row;
            if ($row['isin'] !== '') {
                $sourceIsins[$row['isin']][] = $row;
            }
        }
        foreach ($stocks as $stock) {
            if (($stock['exchange'] ?? '') === 'BSE' && trim((string) ($stock['bse_scrip_code'] ?? '')) !== '') {
                $masterCodes[(string) $stock['bse_scrip_code']][] = (int) $stock['id'];
            }
            $isin = strtoupper(trim((string) ($stock['isin'] ?? '')));
            if ($isin !== '') {
                $masterIsins[$isin][] = (int) $stock['id'];
            }
        }
        $targets = $outcomes = $counts = [];
        foreach ($stocks as $stock) {
            if (($stock['exchange'] ?? '') !== 'BSE' || ! ($stock['is_active'] ?? false)
                || ($stock['admin_deactivated'] ?? false) || ($stock['is_benchmark'] ?? false)) {
                continue;
            }
            $id = (int) $stock['id'];
            $symbol = strtoupper(trim((string) $stock['symbol']));
            $code = trim((string) ($stock['bse_scrip_code'] ?? ''));
            $current = (string) ($stock['isin'] ?? '');
            $rows = $byCode[$code] ?? [];
            $row = count($rows) === 1 ? $rows[0] : null;
            $reason = match (true) {
                preg_match('/^\d{5,6}$/', $code) !== 1 => 'master_code_unavailable',
                count($masterCodes[$code] ?? []) !== 1 => 'ambiguous_master_code',
                count($rows) === 0 => 'source_code_absent',
                count($rows) !== 1 => 'ambiguous_source_code',
                $row['symbol'] !== $symbol => 'symbol_change_requires_review',
                preg_match('/^IN[A-Z0-9]{9}[0-9]$/', $row['isin']) !== 1 => 'source_isin_unavailable',
                count($sourceIsins[$row['isin']] ?? []) !== 1 => 'ambiguous_source_isin',
                $current !== '' && strtoupper(trim($current)) !== $row['isin'] => 'existing_isin_conflict',
                $current !== '' => 'already_identified',
                ($masterIsins[$row['isin']] ?? []) !== [] => 'existing_master_isin_collision',
                default => 'proposed',
            };
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            $outcomes[] = ['stock_id' => $id, 'symbol' => $symbol, 'scrip_code' => $code, 'reason' => $reason];
            if ($reason === 'proposed') {
                $targets[] = [
                    'stock_id' => $id, 'symbol' => $symbol, 'scrip_code' => $code,
                    'old_isin' => $stock['isin'] ?? null, 'new_isin' => $row['isin'],
                ];
            }
        }
        ksort($counts);
        $document = [
            'version' => 2, 'operation' => 'fill_missing_bse_isins',
            'source' => 'bse_active_equity', 'source_status' => 'Active',
            'source_rows_sha256' => $this->hashValue($source),
            'master_rows_sha256' => $this->hashValue($stocks),
            'source_rows' => count($source), 'candidate_count' => count($targets),
            'reason_counts' => $counts, 'targets' => $targets, 'outcomes' => $outcomes,
        ];
        $document['plan_sha256'] = $this->hashPlan($document);

        return $document;
    }

    /** Semantic JSON hash survives native-JSON object key normalization. */
    public function hashPlan(array $plan): string
    {
        unset($plan['plan_sha256']);

        return $this->hashValue($plan);
    }

    private function hashValue(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            $item = array_map($normalize, $item);
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }
}
