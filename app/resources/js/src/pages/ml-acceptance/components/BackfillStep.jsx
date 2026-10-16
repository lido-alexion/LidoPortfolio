import React, { useState } from 'react';
import StepCard from './StepCard';
import InfoHelp from './InfoHelp';

export default function BackfillStep({ wizard, selectedSources, base, api, run, busy, canMutate }) {
    const [confirmApply, setConfirmApply] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const backfill = wizard?.backfill;
    const requested = backfill?.requested_dates || [];
    const results = backfill?.results || {};
    const canStartPreview = !backfill || backfill.can_retry_preview === true;
    const runPreview = () => run('preview', () => api.post(`${base}/backfills/preview`, { sources: selectedSources, campaign_id: wizard?.campaign?.id }));
    const apply = () => run('apply', () => api.post(`${base}/backfills/${backfill.id}/apply`));
    return <StepCard number={4} title="Preview & apply backfill" what="Preview shows proposed historical membership changes without writing them. Apply is a separate confirmed action; existing conflicting or unproven history is blocked." learn="Backfill reconstructs historical universe membership from sealed dated sources. StoX rechecks source and mapped-membership hashes when applying. Matching proven data is idempotent, and existing history is never overwritten.">
        <p>Selected sealed sources: {selectedSources.length}. Preview each required date before applying any reviewed changes. <InfoHelp label="backfill">Rebuild missing historical universe membership from validated dated sources. Preview performs no membership writes; Apply rechecks source and membership hashes.</InfoHelp></p>
        {canMutate && canStartPreview && <button type="button" className="btn btn-outline-primary me-2" disabled={busy || selectedSources.length === 0 || !wizard?.campaign?.id} onClick={runPreview}>Run safe preview</button>}
        {backfill && <div className="mt-3">
            <p>Backfill status: <strong>{backfill.status}</strong>; mode: {backfill.mode || 'unknown'}; progress {backfill.cursor || 0} of {requested.length} dates.</p>
            {['queued', 'running'].includes(backfill.status) && <p role="status" aria-live="polite">This operation continues on the server if you close the browser.</p>}
            {requested.map((date) => {
                const result = results[date] || {};
                const diagnostics = result.diagnostics || {};
                return <article className="card mb-2" key={date}><div className="card-body py-2">
                    <h4 className="h6">{date}</h4>
                    <p className="mb-1">{result.status || (result.snapshot_sha256 ? 'Reviewed' : 'Waiting')}{diagnostics.mapping_percentage !== undefined ? ` · ${diagnostics.mapping_percentage}% mapped` : ''}{result.existing ? ' · matching proven history exists' : ''}</p>
                    {diagnostics.source_company_equity_member_count !== undefined && <p className="mb-1">Source members: {diagnostics.source_company_equity_member_count}; mapped to StoX: {diagnostics.mapped_count ?? 'unknown'}; unknown: {diagnostics.unmapped_count ?? 'unknown'}.</p>}
                    {(diagnostics.source_validated_date || diagnostics.source_date_basis || diagnostics.parser_version) && <p className="mb-1">Provenance: source date {diagnostics.source_validated_date || 'unknown'}; date basis {diagnostics.source_date_basis || 'unknown'}; parser {diagnostics.parser_version || 'unknown'}.</p>}
                    {result.status === 'blocked' && <p className="text-danger">This date is blocked. Existing or proposed history was not overwritten.</p>}
                </div></article>;
            })}
            {canMutate && backfill.can_apply === true && <button type="button" className="btn btn-warning" disabled={busy} onClick={() => setConfirmApply(true)}>Apply reviewed backfill</button>}
            {canMutate && backfill.resumable === true && backfill.status === 'failed' && <button type="button" className="btn btn-outline-secondary ms-2" disabled={busy} onClick={() => run('backfill-resume', () => api.post(`${base}/backfills/${backfill.id}/resume`))}>Resume backfill</button>}
            {canMutate && ['queued', 'running'].includes(backfill.status) && <button type="button" className="btn btn-outline-danger ms-2" disabled={busy} onClick={() => setConfirmCancel(true)}>Cancel between work units</button>}
        </div>}
        {confirmApply && <div className="alert alert-warning mt-3" role="alertdialog" aria-labelledby="apply-backfill-title" aria-describedby="apply-backfill-copy"><h4 className="h6" id="apply-backfill-title">Confirm historical membership changes</h4><p id="apply-backfill-copy">Apply the reviewed historical membership changes for the listed dates. Matching proven data is idempotent. Conflicting or unproven existing history will be blocked and will not be overwritten.</p><button type="button" className="btn btn-warning me-2" disabled={busy} onClick={async () => { setConfirmApply(false); await apply(); }}>Confirm apply</button><button type="button" className="btn btn-outline-secondary" onClick={() => setConfirmApply(false)}>Go back</button></div>}
        {confirmCancel && <div className="alert alert-warning mt-3" role="alertdialog" aria-labelledby="cancel-backfill-title"><h4 className="h6" id="cancel-backfill-title">Confirm backfill cancellation</h4><p>StoX will stop between work units. Completed date evidence is retained and will not be deleted.</p><button type="button" className="btn btn-outline-danger me-2" disabled={busy} onClick={async () => { setConfirmCancel(false); await run('backfill-cancel', () => api.post(`${base}/backfills/${backfill.id}/cancel`)); }}>Confirm cancellation</button><button type="button" className="btn btn-outline-secondary" onClick={() => setConfirmCancel(false)}>Keep backfill running</button></div>}
    </StepCard>;
}
