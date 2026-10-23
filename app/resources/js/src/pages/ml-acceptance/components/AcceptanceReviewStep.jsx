import React from 'react';
import StepCard from './StepCard';
import HorizonStatusCard from './HorizonStatusCard';
import InfoHelp from './InfoHelp';

export default function AcceptanceReviewStep({ wizard, report, onFinish, finished }) {
    const campaign = wizard?.campaign;
    const qualified = campaign?.status === 'qualified' && report?.evidence_current;
    const stale = campaign?.status === 'qualified' && !report?.evidence_current;
    let outcome = qualified ? 'Production acceptance is current for this build and configuration.'
        : stale ? 'Saved evidence is stale because the current build or configuration no longer matches.'
            : campaign?.status === 'local_complete' ? 'The runs completed locally, but local evidence does not qualify production.'
                : campaign?.status === 'failed' ? 'The acceptance operation failed. Existing evidence is retained.'
                    : campaign?.status === 'cancelled' ? 'The campaign was cancelled. Completed evidence is retained; a fresh campaign is the restart path.'
                        : campaign?.status === 'blocked' ? 'Preflight is blocked. Runtime completion and candidate quality remain distinct.'
                            : 'Production qualification is not yet recorded.';
    const lifecycle = wizard?.lifecycle || {};
    const savedIdentity = campaign?.identity || {};
    const currentIdentity = wizard?.identity || {};
    return <StepCard number={7} title="Review completion" what="Review production qualification, each horizon’s operational result and what remains a separate Admin decision." learn="Operational completion proves the deployed runtime executed and persisted evidence. Candidate quality determines eligibility separately. Promotion, schedules, lifecycle automation and drift are separate actions in their existing controls.">
        <p className={qualified ? 'alert alert-success' : stale ? 'alert alert-warning' : 'alert alert-info'}>{outcome}</p>
        {campaign && <p>Campaign {campaign.id} · status {campaign.status} · qualified {campaign.qualified_at || 'not qualified'}.</p>}
        {campaign?.status === 'qualified' && <><p>Evidence freshness is reported by the server: {report?.evidence_current === true ? 'current' : 'stale'}. StoX marks evidence stale when its identity no longer matches or it is more than 30 days old.</p><dl className="row"><dt className="col-sm-4">Saved / current build</dt><dd className="col-sm-8">{savedIdentity.build_id || 'Unknown'} / {currentIdentity.build_id || 'Unknown'}</dd><dt className="col-sm-4">Saved / current registry</dt><dd className="col-sm-8">{savedIdentity.registry || 'Unknown'} / {currentIdentity.registry || 'Unknown'}</dd><dt className="col-sm-4">Saved / current configuration</dt><dd className="col-sm-8">{savedIdentity.configuration_sha256 || 'Unknown'} / {currentIdentity.configuration_sha256 || 'Unknown'}</dd><dt className="col-sm-4">Saved / current commit</dt><dd className="col-sm-8">{savedIdentity.commit_sha || 'Unknown'} / {currentIdentity.commit_sha || 'Unknown'}</dd></dl></>}
        {Object.entries(wizard?.horizons || {}).map(([horizon, item]) => <HorizonStatusCard key={horizon} horizon={horizon} evidence={item} />)}
        <h4 className="h6 mt-3">Separate follow-up decisions</h4>
        <ul><li>Active models are unchanged by acceptance. Review candidate evidence and promotion eligibility in the existing model review controls. <InfoHelp label="promotion">Promotion is a separate Admin choice that makes one eligible candidate active for one horizon.</InfoHelp></li><li>Lifecycle: {lifecycle.enabled ? 'enabled' : 'disabled'}. <InfoHelp label="lifecycle automation">Scheduling or drift-triggered work may create future candidates. It does not promote or roll back a model.</InfoHelp></li><li>Drift trigger: {lifecycle.drift_trigger_enabled ? 'enabled' : 'disabled'}. <InfoHelp label="drift">A material change in live data compared with training data; it may request a new candidate when separately enabled.</InfoHelp></li>{Object.entries(lifecycle.horizons || {}).map(([h, settings]) => <li key={h}>{h} schedule: {settings.enabled ? 'enabled' : 'disabled'}.</li>)}</ul>
        <p>The wizard records production evidence. It will not activate a model or enable schedules.</p>
        <p><a href="#acceptance-advanced-diagnostics">Open full safe acceptance evidence and diagnostics</a>.</p>
        {!finished && <button type="button" className="btn btn-outline-primary" onClick={onFinish}>Finish</button>}
        {finished && <p role="status">Acceptance review is collapsed to this completion summary.</p>}
        {qualified && <a className="btn btn-link ps-0" href="#ml-candidate-review">Open existing candidate and promotion review</a>}
    </StepCard>;
}
