export const acceptanceReasons = {
    preflight_not_recorded: { title: 'Readiness check not run', explanation: 'Run a discovery preflight so StoX can identify the exact dates and coverage it needs.', step: 2, action: 'Run discovery preflight', severity: 'info' },
    queue_configuration_not_ready: { title: 'Production worker configuration is not ready', explanation: 'The dedicated production worker configuration is not ready for long-running acceptance work. Ask an operator to review the acceptance runtime configuration.', step: 1, action: 'Review readiness details', severity: 'blocking' },
    missing_required_source_date: { title: 'A dated source is required', explanation: 'Upload and validate the official NSE file for each date listed below.', step: 3, action: 'Add historical sources', severity: 'blocking' },
    source_selection_required: { title: 'Choose a sealed source for each required date', explanation: 'Select one sealed, date-matched official source for every required date before previewing the backfill.', step: 3, action: 'Select required sources', severity: 'info' },
    preflight_blocked: { title: 'Preflight found blockers', explanation: 'Review the horizon results and follow the listed data or runtime remediation before continuing.', step: 2, action: 'Review preflight results', severity: 'blocking' },
    backfill_failed: { title: 'Backfill did not complete', explanation: 'Review per-date preview evidence and the safe error summary, then resume only if the server reports a resumable operation.', step: 4, action: 'Review backfill', severity: 'blocking' },
    backfill_evidence_incomplete: { title: 'Backfill evidence is incomplete', explanation: 'The saved operation does not prove that every requested date completed. A preview can be run again; an applied operation needs operator review.', step: 4, action: 'Review backfill evidence', severity: 'blocking' },
    required_reference_dates_unavailable: { title: 'Required dates are unavailable', explanation: 'The canonical dataset builder could not determine the reference dates for this horizon.', step: 2, action: 'Review preflight evidence', severity: 'blocking' },
    membership_coverage_incomplete: { title: 'Historical membership is incomplete', explanation: 'Required historical universe membership has not been proven for every date.', step: 3, action: 'Review missing dates', severity: 'blocking' },
    snapshot_unproven: { title: 'Historical source evidence is missing or unproven', explanation: 'A dated source must be validated and safely mapped before this date can pass preflight.', step: 3, action: 'Add the required source', severity: 'blocking' },
    core_fundamentals_unavailable: { title: 'Core historical fundamentals are unavailable', explanation: 'The canonical dataset has no usable point-in-time value for a required fundamental feature. Review the named feature and its dated evidence.', step: 5, action: 'Review final preflight evidence', severity: 'blocking' },
    historical_breadth_unavailable: { title: 'Historical breadth coverage is missing', explanation: 'The canonical dataset has no usable historical breadth observations for this horizon.', step: 5, action: 'Review final preflight', severity: 'blocking' },
    pit_evidence_quota_exceeded: { title: 'Private evidence storage is full', explanation: 'The acceptance evidence quota has been reached. Existing evidence is retained; an operator must review storage before another campaign.', step: 5, action: 'Review operator storage', severity: 'blocking' },
    canonical_dataset_or_coverage_unavailable: { title: 'Dataset preparation did not complete', explanation: 'StoX could not prepare or inspect the canonical dataset. Review the safe diagnostics and server evidence.', step: 5, action: 'Review final preflight', severity: 'blocking' },
    stale_build_configuration_identity: { title: 'Saved evidence is stale', explanation: 'The saved evidence belongs to a different build or configuration. Run a fresh campaign to recheck current inputs.', step: 5, action: 'Run a fresh preflight', severity: 'blocking' },
    acceptance_migration_missing: { title: 'Acceptance storage is unavailable', explanation: 'The acceptance database migration is not available in this environment.', step: 1, action: 'Review deployment readiness', severity: 'blocking' },
    current_production_acceptance_required: { title: 'Current production acceptance is required', explanation: 'A recent qualified campaign matching the deployed build, registry and configuration is required.', step: 1, action: 'Review readiness', severity: 'blocking' },
};

const reasonCategories = {
    preflight_not_recorded: 'Data',
    queue_configuration_not_ready: 'Runtime',
    acceptance_migration_missing: 'Runtime',
    current_production_acceptance_required: 'Runtime',
    preflight_blocked: 'Runtime',
    missing_required_source_date: 'Data',
    source_selection_required: 'Data',
    membership_coverage_incomplete: 'Data',
    snapshot_unproven: 'Data',
    backfill_failed: 'Data',
    backfill_evidence_incomplete: 'Data',
    required_reference_dates_unavailable: 'Dataset',
    core_fundamentals_unavailable: 'Dataset',
    historical_breadth_unavailable: 'Dataset',
    pit_evidence_quota_exceeded: 'Dataset',
    canonical_dataset_or_coverage_unavailable: 'Dataset',
    stale_build_configuration_identity: 'Runtime',
};

export function describeAcceptanceReason(code) {
    const known = acceptanceReasons[code];
    return known ? { ...known, category: reasonCategories[code] || 'Other', code }
        : { title: 'StoX reported an unrecognized blocker', explanation: 'Open Advanced diagnostics and copy the blocker code for investigation.', step: null, action: 'Open Advanced diagnostics', severity: 'blocking', category: 'Other', code };
}

export function reasonForHorizon(code) {
    const [baseCode] = String(code || '').split(':');
    return describeAcceptanceReason(baseCode);
}
