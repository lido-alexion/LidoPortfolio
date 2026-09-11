import React, { useCallback, useEffect, useState } from 'react';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

const label = (value) => value ? value.replaceAll('_', ' ') : 'Not yet checked';
const tone = (value) => value === 'reconciled' ? 'text-success' : value === 'mismatch' || value === 'attention_required' ? 'text-danger' : 'text-muted';

export default function PortfolioReconciliationCard({ executionMode }) {
    const eligible = executionMode === 'semi_automatic' || executionMode === 'automatic';
    const [snapshot, setSnapshot] = useState(null);
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (!eligible) return;
        const response = await api.get('/reconciliation', { skipErrorToast: true });
        setSnapshot(response.data?.data || null);
    }, [eligible]);

    useEffect(() => {
        setSelected(null);
        if (eligible) load().catch(() => setSnapshot(null));
    }, [eligible, load]);

    if (!eligible) return null;

    const reconcile = async () => {
        setBusy(true);
        try {
            await api.post('/reconciliation', {}, { skipErrorToast: true });
            await load();
            showToast('Portfolio reconciliation completed', 'success');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not run reconciliation'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const openRun = async (id) => {
        try {
            const response = await api.get(`/reconciliation/${id}`, { skipErrorToast: true });
            setSelected(response.data?.data || null);
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not load reconciliation evidence'), 'danger');
        }
    };

    const status = snapshot?.status || {};
    const discrepancies = selected?.discrepancies || {};

    return (
        <div className="card h-100">
            <div className="card-header d-flex justify-content-between align-items-center gap-2">
                <span>Broker portfolio reconciliation</span>
                <button type="button" className="btn btn-outline-primary btn-sm" disabled={busy} onClick={reconcile}>
                    {busy ? 'Reconciling…' : 'Run now'}
                </button>
            </div>
            <div className="card-body">
                {!snapshot ? <p className="small text-muted mb-0">Reconciliation status is unavailable.</p> : <>
                    <div className="d-flex flex-wrap gap-4 mb-2 small">
                        <span>Overall: <strong className={tone(status.overall)}>{label(status.overall)}</strong></span>
                        <span>Holdings: <strong className={tone(status.holdings)}>{label(status.holdings)}</strong></span>
                        <span>Funds: <strong className={tone(status.funds)}>{label(status.funds)}</strong></span>
                    </div>
                    {status.execution_blocked ? <div className="alert alert-danger py-2 small">New broker execution is blocked until a successful reconciliation confirms that holdings match.</div> : null}
                    {status.last_failure ? <div className="alert alert-warning py-2 small">Latest sync failed: {status.last_failure}. The last successful result remains authoritative.</div> : null}
                    <p className="small text-muted">Last successful check: {status.last_successful_at ? new Date(status.last_successful_at).toLocaleString() : 'Never'}. Correct discrepancies through normal holdings or cash transactions; broker values are never applied automatically.</p>
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead><tr><th>Run</th><th>Trigger</th><th>Result</th><th>Completed</th><th /></tr></thead>
                            <tbody>{(snapshot.runs || []).slice(0, 10).map((run) => <tr key={run.id}>
                                <td>#{run.id}</td><td>{label(run.trigger)}</td><td className={tone(run.overall_status)}>{run.status === 'sync_failed' ? 'Sync failed' : label(run.overall_status)}</td>
                                <td>{run.completed_at ? new Date(run.completed_at).toLocaleString() : '—'}</td>
                                <td><button type="button" className="btn btn-link btn-sm p-0" onClick={() => openRun(run.id)}>Evidence</button></td>
                            </tr>)}</tbody>
                        </table>
                    </div>
                    {selected ? <div className="border rounded p-3 mt-3 small">
                        <div className="d-flex justify-content-between"><strong>Run #{selected.id} evidence</strong><button type="button" className="btn-close" aria-label="Close evidence" onClick={() => setSelected(null)} /></div>
                        {selected.failure ? <p className="text-warning mb-1">Sync failure: {selected.failure}</p> : null}
                        {(discrepancies.holdings || []).map((row) => <div key={row.symbol}>{row.symbol}: StoX {row.stoxQty} shares; Kite {row.brokerQty}; difference {Number(row.brokerQty) - Number(row.stoxQty)}</div>)}
                        {selected.funds_status === 'mismatch' ? <div>Cash: StoX ₹{discrepancies.funds?.stox_cash}; Kite ₹{discrepancies.funds?.broker_cash}; difference ₹{discrepancies.funds?.difference}</div> : null}
                        {(selected.unsupported_instruments || []).length ? <div className="text-muted mt-1">Informational unsupported Kite instruments: {selected.unsupported_instruments.map((row) => row.symbol).join(', ')}</div> : null}
                    </div> : null}
                </>}
            </div>
        </div>
    );
}
