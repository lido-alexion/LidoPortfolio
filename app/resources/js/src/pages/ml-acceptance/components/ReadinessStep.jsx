import React from 'react';
import StepCard from './StepCard';
import InfoHelp from './InfoHelp';

export default function ReadinessStep({ wizard, report, onRefresh, canMutate, busy }) {
    const identity = wizard?.identity || {};
    const lifecycle = wizard?.lifecycle || {};
    const workerEvidence = wizard?.runtime?.worker_evidence || {};
    const pythonEvidence = wizard?.runtime?.python_evidence || {};
    return <StepCard number={1} title="Understand & check readiness" what="StoX checks whether this deployed environment can safely record production acceptance evidence. This wizard does not activate a model or enable schedules." learn="Production acceptance proves the deployed build can prepare historical data and run 1m, 3m and 6m candidates through the configured worker and real Python adapter. Promotion and lifecycle automation remain separate Admin decisions.">
        <p>This process checks readiness, discovers data needs, validates dated NSE sources, reviews any historical backfill, runs a final readiness check, trains candidates only after your confirmation, and then shows the evidence.</p>
        <ol className="acceptance-overview-list">{['Check production readiness', 'Discover required dates', 'Add official dated sources', 'Preview and apply reviewed history', 'Run final preflight', 'Explicitly train 1m / 3m / 6m candidates', 'Review evidence and separate decisions'].map((item) => <li key={item}>{item}</li>)}</ol>
        <div className="row g-2 mb-3">
            <div className="col-12 col-md-6"><strong>Build:</strong> {identity.build_id || 'Not available'}<br /><strong>Environment:</strong> {identity.environment || 'Unknown'}<br /><strong>Registry:</strong> {identity.registry || 'Unknown'}</div>
            <div className="col-12 col-md-6"><strong>Configuration:</strong> {identity.configuration_sha256 || 'Unknown'}<br /><strong>Commit:</strong> {identity.commit_sha || 'Unknown'}<br /><strong>Dedicated queue:</strong> {wizard?.runtime?.queue_configuration_ready ? 'Configured; worker execution still requires evidence' : 'Not ready'}</div>
        </div>
        <p><strong>Migrations:</strong> {Object.values(report?.migrations || {}).every(Boolean) ? 'Acceptance data tables are present' : 'One or more required tables are unavailable'}</p>
        <p><strong>Worker and Python evidence:</strong> Worker observation times are recorded during a real queued preflight. Python evidence is recorded by the canonical adapter during training. Before either has run, the state is “Not yet observed.” <InfoHelp label="worker">A background process that performs long-running acceptance work. The browser can close while the dedicated production worker continues and saves progress.</InfoHelp></p>
        <ul>{['1m', '3m', '6m'].map((horizon) => {
            const python = pythonEvidence[horizon];
            return <li key={horizon}>{horizon}: worker {workerEvidence[horizon] || 'Not yet observed'}; Python {python?.adapter ? `${python.adapter} in ${python.environment || 'unknown environment'}` : 'Not yet observed'}.</li>;
        })}</ul>
        <p><strong>Lifecycle:</strong> {lifecycle.enabled ? 'Enabled' : 'Disabled'}; schedules and drift settings are shown separately in completion review.</p>
        <button type="button" className="btn btn-outline-secondary" disabled={busy} onClick={onRefresh}>Refresh readiness</button>
        {canMutate && <p className="mt-3 mb-0">Continue when the dedicated queue configuration is ready. The first preflight can discover required dates.</p>}
    </StepCard>;
}
