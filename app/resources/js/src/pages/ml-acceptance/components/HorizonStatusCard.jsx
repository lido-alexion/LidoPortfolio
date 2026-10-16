import React from 'react';
import { reasonForHorizon } from '../acceptanceReasonRegistry';
import InfoHelp from './InfoHelp';

export default function HorizonStatusCard({ horizon, evidence }) {
    const reasons = evidence?.blocking_reasons || [];
    const training = evidence?.training || {};
    const progress = evidence?.run_progress || {};
    const rejected = training.status === 'completed_rejected';
    const groupedReasons = reasons.reduce((groups, code) => {
        const reason = reasonForHorizon(code);
        (groups[reason.category] ||= []).push({ code, reason });
        return groups;
    }, {});
    return <article className="card mb-2">
        <div className="card-body py-2">
            <h4 className="h6">{horizon} horizon <InfoHelp label={`${horizon} horizon`}>The future period a model estimates. StoX trains and evaluates separate 1m, 3m and 6m candidates because useful signals and outcomes differ by horizon.</InfoHelp></h4>
            <p className="mb-1">Preflight: {reasons.length ? 'Blocked' : (evidence?.dataset_sha256 ? 'Ready' : 'Not yet recorded')}</p>
            {Object.entries(groupedReasons).map(([category, items]) => <div key={category}><h5 className="small fw-bold">{category} blockers</h5><ul>{items.map(({ code, reason }, index) => <li key={`${code}-${index}`}><strong>{reason.title}:</strong> {reason.explanation} <code>{code}</code></li>)}</ul></div>)}
            {(training.status || progress.status) && <p className="mb-0">Training run {training.run_id || progress.run_id || '—'}: {rejected ? 'Completed — quality gates not met' : (training.status || progress.status)}{progress.stage ? ` · ${progress.stage}` : ''}{progress.percent !== null && progress.percent !== undefined ? ` · ${progress.percent}%` : ''}{progress.observed_at ? ` · updated ${progress.observed_at}` : ''}{progress.retry_attempt ? ` · attempt ${progress.retry_attempt}` : ''}{training.evidence_complete === false && !rejected ? ' (evidence incomplete)' : ''}</p>}
        </div>
    </article>;
}
