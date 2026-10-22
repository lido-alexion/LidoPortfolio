import React from 'react';
import StepCard from './StepCard';
import HorizonStatusCard from './HorizonStatusCard';
import InfoHelp from './InfoHelp';

export default function FinalPreflightStep({ wizard, cutoff, setCutoff, run, base, api, busy, canMutate }) {
    const campaign = wizard?.campaign;
    const applied = wizard?.backfill?.status === 'completed' && wizard?.backfill?.mode === 'apply' && wizard?.steps?.[3]?.status === 'complete';
    return <StepCard number={5} title="Run final preflight" what="After source and backfill work, a fresh preflight rechecks the current datasets for all three horizons. Training stays unavailable until all three pass." learn="Preflight records required reference dates, mapping, breadth, sector and fundamental coverage, dataset hashes and blockers. A changed source, build, registry, configuration or cutoff can make older readiness stale.">
        <p>Resolved data work: {applied ? 'A reviewed backfill apply is complete.' : 'No completed backfill apply is recorded.'} <InfoHelp label="preflight">A readiness check on the canonical historical datasets. It records coverage and blockers; it does not train or activate a model.</InfoHelp></p>
        <label className="form-label" htmlFor="final-cutoff">Training cutoff</label><input id="final-cutoff" className="form-control mb-2" type="date" value={cutoff} onChange={(e) => setCutoff(e.target.value)} />
        <p className="small text-muted">Changing the cutoff can change the historical dates StoX needs.</p>
        {canMutate && <button type="button" className="btn btn-primary" disabled={busy || !cutoff} onClick={() => run('final-preflight', () => api.post(`${base}/campaigns`, {
            cutoff_date: cutoff, final_preflight: true,
            ...(applied && wizard.backfill?.id && wizard.campaign?.cutoff_date === cutoff ? { remediation_backfill_id: wizard.backfill.id } : {}),
        }))}>Run final preflight</button>}
        <div className="mt-3">{Object.entries(wizard?.horizons || {}).map(([horizon, item]) => <HorizonStatusCard key={horizon} horizon={horizon} evidence={item} />)}</div>
        {campaign && <details><summary>Dataset hashes and technical evidence</summary><pre className="small">{JSON.stringify(Object.fromEntries(Object.entries(wizard.horizons || {}).map(([h, item]) => [h, { dataset_sha256: item.dataset_sha256, coverage: item.coverage }])), null, 2)}</pre></details>}
    </StepCard>;
}
