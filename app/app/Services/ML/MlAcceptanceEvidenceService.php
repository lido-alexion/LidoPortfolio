<?php
namespace App\Services\ML;

use App\Exceptions\MlAcceptanceEvidenceQuotaExceeded;
use App\Models\V7\FundamentalFact;
use App\Models\V8\MlUniverseSnapshotBoundary;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class MlAcceptanceEvidenceService
{
    public const FACTS = [
        'roe' => ['net_income', 'equity'], 'debt_equity' => ['debt', 'equity'],
        'revenue_growth_proxy' => ['revenue'], 'eps_growth_yoy' => ['eps'], 'net_income_growth_yoy' => ['net_income'],
        'operating_margin' => ['operating_profit', 'revenue'], 'net_margin' => ['net_income', 'revenue'],
        'fcf_margin' => ['operating_cash_flow', 'capital_expenditure', 'revenue'],
        'ocf_to_net_income_ratio' => ['operating_cash_flow', 'net_income'], 'pe_ratio' => ['eps'], 'pb_ratio' => ['equity'],
        'net_debt_equity' => ['debt', 'cash_and_equivalents', 'equity'], 'current_ratio' => ['current_assets', 'current_liabilities'],
        'gross_margin' => ['gross_profit', 'revenue'], 'gross_npa_ratio' => ['gross_npa_ratio'], 'net_npa_ratio' => ['net_npa_ratio'],
        'capital_adequacy_ratio' => ['capital_adequacy_ratio'], 'net_interest_margin' => ['net_interest_margin', 'net_interest_income', 'interest_income'],
    ];

    public function preflight(string $campaign, string $horizon, Carbon $cutoff): array
    {
        $builder = app(MlTrainingDatasetBuilder::class);
        $dates = array_keys($builder->requiredReferenceDates($horizon, $cutoff));
        $membership = app(MlHistoricalUniverseMembershipService::class)->coverageForDates($dates);
        $snapshots = [];
        $blocked = [];
        if ($dates === []) $blocked[] = 'required_reference_dates_unavailable';
        if ($membership['coverage_percentage'] !== 100.0) $blocked[] = 'membership_coverage_incomplete';
        foreach ($dates as $date) {
            $boundary = MlUniverseSnapshotBoundary::query()->where('universe_key', MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE)->whereDate('effective_from', $date)->first();
            $diagnostics = $boundary?->quality_diagnostics ?? [];
            $valid = $boundary !== null && ($diagnostics['mapping_percentage'] ?? 0) >= 90
                && ($diagnostics['source_validated_date'] ?? null) === $date
                && ! empty($diagnostics['parser_version']) && ! empty($diagnostics['source_sha256']);
            $snapshots[$date] = ['status' => $valid ? 'covered' : 'missing_or_unproven', 'source' => $boundary?->source,
                'snapshot_key' => $boundary?->snapshot_key, 'diagnostics' => array_intersect_key($diagnostics, array_flip([
                    'source_id', 'source_sha256', 'membership_sha256', 'source_validated_date', 'requested_date', 'effective_date', 'source_date_basis',
                    'format_version', 'source_company_equity_member_count', 'mapped_count', 'unmapped_count', 'mapping_percentage', 'parser_version',
                ]))];
            if (! $valid) $blocked[] = 'snapshot_unproven:'.$date;
        }
        $result = ['reference_dates' => $dates, 'membership' => $membership, 'snapshots' => $snapshots, 'blocking_reasons' => $blocked,
            'coverage' => null, 'dataset_sha256' => null, 'observed_at' => now()->toIso8601String()];
        if ($blocked !== []) return $result;
        $directory = storage_path('app/private/ml-acceptance/campaigns/'.$campaign.'/'.$horizon);
        File::ensureDirectoryExists($directory, 0700, true);
        try {
            $dataset = $builder->buildStreamed($horizon, $cutoff, $directory.'/dataset');
            $result += $this->coverage($dataset, $horizon, $directory);
            $result['coverage'] = $result['feature_coverage'];
            $result['dataset_sha256'] = $this->datasetHash($dataset);
            $result['partitions'] = $dataset['partitions'];
            $result['context'] = $dataset['diagnostics']['context_coverage'] ?? null;
            foreach ($result['profile']['feature_metadata'] as $key => $meta) {
                if (($meta['tier'] ?? '') === 'core' && isset(self::FACTS[$key]) && ($result['feature_coverage']['train'][$key]['present'] ?? 0) === 0) $result['blocking_reasons'][] = 'core_fundamentals_unavailable:'.$key;
            }
            if (($result['feature_coverage']['train']['market_breadth_nifty']['present'] ?? 0) === 0) $result['blocking_reasons'][] = 'historical_breadth_unavailable';
        } catch (MlAcceptanceEvidenceQuotaExceeded) {
            $result['blocking_reasons'][] = 'pit_evidence_quota_exceeded';
        } catch (\Throwable $e) {
            $result['blocking_reasons'][] = 'canonical_dataset_or_coverage_unavailable';
        } finally { File::deleteDirectory($directory.'/dataset'); }
        return $result;
    }

    public function datasetHash(array $dataset): string
    {
        $hashes = [];
        foreach ($dataset['paths'] as $partition => $path) $hashes[$partition] = hash_file('sha256', $path);
        return hash('sha256', json_encode([$hashes, $dataset['partitions'], $dataset['feature_definitions']], JSON_THROW_ON_ERROR));
    }

    /** Coverage is computed from actual canonical values; available facts do not imply a usable value. */
    public function coverage(array $dataset, string $horizon, string $directory): array
    {
        $profile = app(MlFeatureRegistryService::class)->featureSetForHorizon($horizon);
        $coverage = $byDate = $excluded = [];
        $journal = new MlAcceptanceEvidenceJournal($directory.'/pit-evidence.jsonl.gz');
        $lastStock = null;
        $facts = [];
        try {
            foreach ($dataset['paths'] as $partition => $path) {
                $handle = fopen($path, 'rb');
                try {
                    while (($line = fgets($handle)) !== false) {
                        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                        $stock = $row['stock_id']; $date = $row['reference_date'];
                        if ($lastStock !== $stock) {
                            $facts = FundamentalFact::query()->where('stock_id', $stock)->where('cadence', 'quarterly')->orderBy('period_end')->get(['id', 'fact_key', 'period_end', 'availability_date', 'revision_number', 'provider', 'value'])->toArray();
                            $journal->append(['stock_id' => $stock, 'partition' => $partition, 'fact_inventory' => $facts]);
                            $lastStock = $stock;
                        }
                        $pitFacts = array_values(array_filter($facts, fn ($fact) => $fact['availability_date'] !== null && substr($fact['availability_date'], 0, 10) <= $date));
                        $used = [];
                        foreach ($profile['feature_keys'] as $key) {
                            $value = $row['features'][$key] ?? null;
                            $present = $key === 'sector' ? is_string($value) && $value !== '__unknown' && $value !== '' : is_numeric($value) && is_finite((float) $value);
                            $counter = $present ? 'present' : 'missing';
                            $coverage[$partition][$key] ??= ['present' => 0, 'missing' => 0];
                            $coverage[$partition][$key][$counter]++;
                            $byDate[$date][$partition][$key] ??= ['present' => 0, 'missing' => 0, 'fact_periods' => []];
                            $byDate[$date][$partition][$key][$counter]++;
                            if (isset(self::FACTS[$key])) {
                                $featureFacts = array_values(array_filter($pitFacts, fn ($fact) => in_array($fact['fact_key'], self::FACTS[$key], true)));
                                $used[$key] = ['value_available' => $present, 'available_pit_fact_ids' => array_column($featureFacts, 'id')];
                                foreach ($featureFacts as $fact) {
                                    $period = $fact['fact_key'].':'.substr((string) $fact['period_end'], 0, 10).':'.substr($fact['availability_date'], 0, 10);
                                    $byDate[$date][$partition][$key]['fact_periods'][$period] = ($byDate[$date][$partition][$key]['fact_periods'][$period] ?? 0) + 1;
                                }
                            }
                        }
                        $journal->append(['stock_id' => $stock, 'reference_date' => $date, 'partition' => $partition, 'fundamentals' => $used]);
                    }
                } finally { fclose($handle); }
            }
            $journalEvidence = $journal->finish();
        } finally { $journal->close(); }
        foreach ($coverage['train'] ?? [] as $key => $counts) if ($counts['present'] === 0) $excluded[$key] = 'no_training_values';
        return ['profile' => $profile, 'feature_coverage' => $coverage, 'feature_date_coverage' => $byDate,
            'exclusions' => $excluded] + $journalEvidence + [
            'pit_evidence_scope' => 'canonical_rows; available PIT facts are provenance, not proof of formula completeness'];
    }
}
