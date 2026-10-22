<?php

namespace App\Services\Fundamentals;

final class BootstrapWarningCategory
{
    public const REJECTED_FACTS = 'facts_rejected_by_quality_gate';
    public const ANOMALY_REVIEW = 'anomaly_guard_review';
    public const MISSING_PROVENANCE = 'missing_provenance';
}
