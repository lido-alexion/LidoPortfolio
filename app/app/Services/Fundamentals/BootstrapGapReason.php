<?php

namespace App\Services\Fundamentals;

use App\Models\V7\FundamentalBootstrapJob;

final class BootstrapGapReason
{
    public const SOURCE_UNAVAILABLE = 'source_unavailable';
    public const METRIC_NOT_REPORTED = 'metric_not_reported';
    public const PERIOD_NOT_APPLICABLE = 'period_not_applicable';
    public const MAPPING_MISSING = 'mapping_missing';
    public const UNSUPPORTED_CADENCE = 'unsupported_cadence';
    public const NOT_ATTEMPTED = 'ingestion_not_yet_attempted';
    public const ATTEMPT_FAILED = 'ingestion_attempted_but_failed';
    public const PREREQUISITE_MISSING = 'derivation_prerequisite_missing';
    public const EVIDENCE_UNKNOWN = 'evidence_unknown';

    public static function forMissingFact(?FundamentalBootstrapJob $job, string $cadence, string $metric, ?string $periodEnd = null): string
    {
        if (! in_array($cadence, ['quarterly', 'annual'], true)) return self::UNSUPPORTED_CADENCE;
        if (! $job) return self::NOT_ATTEMPTED;
        $cadenceEvidence = $job->coverage_json['cadences'][$cadence] ?? [];
        if (($cadenceEvidence['mapping_status'] ?? null) === 'missing') return self::MAPPING_MISSING;
        if ($periodEnd !== null && in_array($periodEnd, (array) ($cadenceEvidence['not_applicable_periods'] ?? []), true)) return self::PERIOD_NOT_APPLICABLE;
        if (in_array($metric, (array) ($cadenceEvidence['missing_prerequisites'] ?? []), true)) return self::PREREQUISITE_MISSING;
        if ($job->status === FundamentalBootstrapJob::STATUS_FAILED) {
            $error = mb_strtolower((string) $job->last_error);
            return str_contains($error, 'unavailable') || str_contains($error, 'connection refused')
                ? self::SOURCE_UNAVAILABLE : self::ATTEMPT_FAILED;
        }
        if (in_array($job->status, [FundamentalBootstrapJob::STATUS_QUEUED, FundamentalBootstrapJob::STATUS_RETRY, FundamentalBootstrapJob::STATUS_RUNNING], true)) return self::NOT_ATTEMPTED;
        if (array_key_exists('observed_fact_keys', $cadenceEvidence)
            && ! in_array($metric, (array) $cadenceEvidence['observed_fact_keys'], true)) return self::METRIC_NOT_REPORTED;
        // No primary-fact period expectation or derived-prerequisite evidence is persisted today.
        return self::EVIDENCE_UNKNOWN;
    }
}
