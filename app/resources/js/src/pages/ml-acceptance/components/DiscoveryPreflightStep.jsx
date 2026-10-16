import React, { useState } from 'react';
import StepCard from './StepCard';
import HorizonStatusCard from './HorizonStatusCard';
import InfoHelp from './InfoHelp';
import ConfirmationPrompt from './ConfirmationPrompt';

export default function DiscoveryPreflightStep({ wizard, cutoff, setCutoff, onRun, onCancel, busy, canMutate }) {
    const [confirmCancel, setConfirmCancel] = useState(false);
    const campaign = wizard?.campaign;
    return <StepCard number={2} title="Discover required data" what="Preflight checks the canonical datasets for all three horizons and lists blockers. It does not train or activate a model." learn="The training cutoff is the latest date allowed in the historical dataset. It fixes the evidence boundary and is not a schedule or execution date. The discovery preflight is the same canonical campaign preflight used for the later final check.">
        <div className="d-flex align-items-center"><label className="form-label mb-0" htmlFor="acceptance-cutoff">Training cutoff</label><InfoHelp label="training cutoff">The latest date allowed in this campaign’s training data. Changing it may change the required dates.</InfoHelp></div>
        <input id="acceptance-cutoff" className="form-control mb-2" type="date" value={cutoff} onChange={(e) => setCutoff(e.target.value)} />
        {!campaign && <p>No production acceptance campaign has been recorded.</p>}
        {campaign && <p>Campaign <code>{campaign.id}</code> — {campaign.status}; cutoff {campaign.cutoff_date || 'unknown'}.</p>}
        {campaign?.status === 'failed' && <p className="alert alert-warning">This campaign failed. Start a fresh reviewed campaign to retry; saved evidence is retained and failed training runs cannot be reused.</p>}
        {canMutate && !['preflight', 'ready', 'training'].includes(campaign?.status) && <button type="button" className="btn btn-primary me-2" disabled={busy || !cutoff} onClick={onRun}>Run discovery preflight</button>}
        {canMutate && ['preflight', 'training', 'ready'].includes(campaign?.status) && <button type="button" className="btn btn-outline-danger" disabled={busy} onClick={() => setConfirmCancel(true)}>Cancel campaign</button>}
        {confirmCancel && <ConfirmationPrompt titleId="cancel-campaign-title" onDismiss={() => setConfirmCancel(false)}><h4 className="h6" id="cancel-campaign-title" data-confirmation-title tabIndex={-1}>Confirm campaign cancellation</h4><p>StoX will request cancellation through the existing acceptance controls. Completed evidence is retained, and cancellation will not start training.</p><button type="button" className="btn btn-outline-danger me-2" disabled={busy} onClick={async () => { setConfirmCancel(false); await onCancel(); }}>Confirm cancellation</button><button type="button" className="btn btn-outline-secondary" onClick={() => setConfirmCancel(false)}>Keep campaign running</button></ConfirmationPrompt>}
        {['preflight', 'training'].includes(campaign?.status) && <p role="status" aria-live="polite" className="mt-2">{campaign.status === 'preflight' ? 'Preflight is running. Closing this page will not stop it.' : 'Training is running. Progress is saved on the server.'}</p>}
        <div className="mt-3">{Object.entries(wizard?.horizons || {}).map(([horizon, item]) => <HorizonStatusCard key={horizon} horizon={horizon} evidence={item} />)}</div>
        {wizard?.required_sources?.length > 0 && <div><h4 className="h6">Required historical dates</h4><ul>{wizard.required_sources.map((item) => <li key={item.date}>{item.date} — needed for {item.horizons.join(', ')}</li>)}</ul></div>}
    </StepCard>;
}
