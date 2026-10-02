import React, { useState } from 'react';
import api from '../api';

const labels = { awaiting_approval: 'Approval required', completed: 'Completed', stale: 'State changed — a fresh plan is required', expired: 'Approval expired', partial_failure: 'Partially completed — execution stopped', verification_failed: 'Verification failed — execution stopped', failed: 'Run failed', rejected: 'Plan rejected', investigating: 'Investigating' };

export default function AssistantRun({ run, onChange, onRetry }) {
    const [destructive, setDestructive] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState('');
    const expired = run.approval_expires_at && new Date(run.approval_expires_at) <= new Date();
    const needsDestructive = (run.preview || []).some(step => step.side_effect === 'destructive');
    async function decide(action) {
        setBusy(true); setError('');
        try {
            const response = await api.post(`/ai/assistant/runs/${run.id}/${action}`, action === 'approve' ? { plan_hash: run.plan_hash, ...(destructive ? { destructive_confirmation: true } : {}) } : {});
            onChange(response.data.data);
        } catch { setError('The action could not be confirmed. Reload run history to check its current state before retrying.'); }
        finally { setBusy(false); }
    }
    return <article className="border rounded p-3 my-3" aria-label="Assistant run">
        <h3 className="h6">{run.objective}</h3><p className="small">Portfolio {run.profile_id}</p>
        <p className="fw-semibold">{run.status === 'awaiting_approval' && expired ? 'Approval expired' : labels[run.status] || run.status}</p>
        {run.answer && <p style={{ whiteSpace: 'pre-wrap' }}>{run.answer}</p>}
        {(run.trace || []).length > 0 && <details><summary>Investigation trace</summary><ol>{run.trace.map((step, i) => <li key={i}>{step.tool.replaceAll('.', ' ')} — {step.status}</li>)}</ol></details>}
        {(run.preview || []).length > 0 && <section aria-label="Mutation preview"><h4 className="h6">Proposed changes</h4><ol>{run.preview.map((step, i) => <li key={i} className="mb-2">
            <strong>{step.consequence}</strong>
            <p className="mb-0">{step.reason}</p>
            {step.arguments?.name && <p className="mb-0">Name: {step.arguments.name}</p>}
            {step.affected_object_id && <p className="mb-0">Object: {step.affected_object_id}</p>}
            {step.arguments?.stock_id && <p className="mb-0">Stock: {step.arguments.stock_id}</p>}
            {step.warning && <p className="text-danger">{step.warning}</p>}
            <span className="small">Validation: {step.validation}</span>
            {step.changes?.length > 0 && <details><summary>Review field changes ({step.changes.length})</summary><dl>{step.changes.map((change, j) => <React.Fragment key={j}><dt>{change.field}</dt><dd style={{ overflowWrap: 'anywhere' }}>{String(change.before ?? 'Unset')} → {String(change.after ?? 'Unset')}</dd></React.Fragment>)}</dl></details>}
        </li>)}</ol></section>}
        {run.status === 'awaiting_approval' && !expired && <>
            <p className="small">Approve this exact group of changes. Current state is checked again before execution.</p>
            {needsDestructive && <label className="d-block my-2"><input type="checkbox" checked={destructive} onChange={event => setDestructive(event.target.checked)} /> I explicitly confirm deleting the listed data.</label>}
            <button className="btn btn-primary me-2" disabled={busy || (needsDestructive && !destructive)} onClick={() => decide('approve')}>Approve changes</button>
            <button className="btn border text-body" disabled={busy} onClick={() => decide('reject')}>Reject plan</button>
        </>}
        {(run.steps || []).length > 0 && <ol aria-label="Action results">{run.steps.map((step, i) => <li key={i}>{step.tool.replaceAll('.', ' ')} — {step.status.replaceAll('_', ' ')}{step.affected_object_id ? ` (object ${step.affected_object_id})` : ''}</li>)}</ol>}
        {['stale', 'expired', 'partial_failure', 'verification_failed', 'failed', 'rejected'].includes(run.status) || (run.status === 'awaiting_approval' && expired) ? <button className="btn border text-body mt-2" disabled={busy} onClick={() => onRetry(run)}>Build a fresh plan</button> : null}
        {error && <p role="alert">{error}</p>}
    </article>;
}
