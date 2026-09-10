import React, { useCallback, useEffect, useState } from 'react';
import api, { getApiErrorMessage } from '../../api';
import { showToast } from '../../toast';

const METHODS = [
    ['next_open', 'Next open'],
    ['next_close', 'Next close'],
    ['ohlc_average', 'OHLC average'],
    ['high_low_midpoint', 'High/low midpoint'],
];

function isoDate(date) {
    return date.toISOString().slice(0, 10);
}

export default function PortfolioReplayPanel({ portfolio }) {
    const today = new Date();
    const prior = new Date(today);
    prior.setUTCFullYear(prior.getUTCFullYear() - 1);
    const [form, setForm] = useState({
        starting_mode: 'new_simulated', period_start: isoDate(prior), period_end: isoDate(today),
        starting_cash: '100000', price_method: 'next_open', adverse_slippage_percent: '0',
    });
    const [runs, setRuns] = useState([]);
    const [readiness, setReadiness] = useState(null);
    const [selected, setSelected] = useState(null);
    const [selectedIds, setSelectedIds] = useState([]);
    const [comparison, setComparison] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (!portfolio?.id) return;
        const response = await api.get('/replays');
        setRuns(response.data?.data || []);
    }, [portfolio?.id]);

    useEffect(() => { load().catch((error) => showToast(getApiErrorMessage(error), 'danger')); }, [load]);

    const payload = () => ({
        ...form,
        ...(form.starting_mode === 'new_simulated' ? { starting_cash: Number(form.starting_cash) } : { starting_cash: null }),
        adverse_slippage_percent: Number(form.adverse_slippage_percent || 0),
    });

    const assess = async () => {
        setBusy(true);
        try {
            const response = await api.post('/replays/readiness', payload());
            setReadiness(response.data?.data || null);
        } catch (error) { showToast(getApiErrorMessage(error), 'danger'); }
        finally { setBusy(false); }
    };

    const start = async () => {
        setBusy(true);
        try {
            const response = await api.post('/replays', payload());
            setReadiness(response.data?.data?.readiness || null);
            await load();
            showToast('Portfolio Replay queued');
        } catch (error) { showToast(getApiErrorMessage(error), 'danger'); }
        finally { setBusy(false); }
    };

    const cancel = async (id) => {
        setBusy(true);
        try { await api.post(`/replays/${id}/cancel`); await load(); showToast('Replay cancelled'); }
        catch (error) { showToast(getApiErrorMessage(error), 'danger'); }
        finally { setBusy(false); }
    };

    const inspect = async (id) => {
        setBusy(true);
        try { const response = await api.get(`/replays/${id}`); setSelected(response.data?.data || null); }
        catch (error) { showToast(getApiErrorMessage(error), 'danger'); }
        finally { setBusy(false); }
    };

    const compare = async () => {
        setBusy(true);
        try { const response = await api.post('/replays/compare', { run_ids: selectedIds }); setComparison(response.data?.data || null); }
        catch (error) { showToast(getApiErrorMessage(error), 'danger'); }
        finally { setBusy(false); }
    };

    const toggleComparison = (id) => setSelectedIds((current) => current.includes(id)
        ? current.filter((value) => value !== id)
        : current.length < 5 ? [...current, id] : current);

    const remove = async (id) => {
        if (!window.confirm('Delete this Replay and its detailed evidence? A minimal audit tombstone will remain.')) return;
        setBusy(true);
        try { await api.delete(`/replays/${id}`); await load(); showToast('Replay deleted; audit tombstone retained'); }
        catch (error) { showToast(getApiErrorMessage(error), 'danger'); }
        finally { setBusy(false); }
    };

    const update = (key, value) => { setForm((current) => ({ ...current, [key]: value })); setReadiness(null); };

    return <div className="card mb-4">
        <div className="card-body">
            <h3 className="h6 mb-1">Portfolio Replay</h3>
            <p className="small text-muted">Historical, deterministic Portfolio simulation. A Replay is isolated from <strong>{portfolio.name}</strong> and cannot be edited after it starts.</p>
            <div className="row g-2 align-items-end">
                <div className="col-md-2"><label className="form-label small">Starting state</label><select className="form-select form-select-sm" value={form.starting_mode} onChange={(e) => update('starting_mode', e.target.value)}><option value="new_simulated">New simulation</option><option value="historical_branch">Historical branch</option></select></div>
                <div className="col-md-2"><label className="form-label small">From</label><input type="date" className="form-control form-control-sm" value={form.period_start} onChange={(e) => update('period_start', e.target.value)} /></div>
                <div className="col-md-2"><label className="form-label small">To</label><input type="date" max={isoDate(today)} className="form-control form-control-sm" value={form.period_end} onChange={(e) => update('period_end', e.target.value)} /></div>
                {form.starting_mode === 'new_simulated' && <div className="col-md-2"><label className="form-label small">Starting cash</label><input type="number" min="0.01" className="form-control form-control-sm" value={form.starting_cash} onChange={(e) => update('starting_cash', e.target.value)} /></div>}
                <div className="col-md-2"><label className="form-label small">Fill method</label><select className="form-select form-select-sm" value={form.price_method} onChange={(e) => update('price_method', e.target.value)}>{METHODS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                <div className="col-md-1"><label className="form-label small">Slippage %</label><input type="number" min="0" max="100" step="0.01" className="form-control form-control-sm" value={form.adverse_slippage_percent} onChange={(e) => update('adverse_slippage_percent', e.target.value)} /></div>
                <div className="col-md-1 d-flex gap-1"><button type="button" className="btn btn-outline-primary btn-sm" disabled={busy} onClick={assess}>Check</button><button type="button" className="btn btn-primary btn-sm" disabled={busy || readiness?.status === 'blocked'} onClick={start}>Start</button></div>
            </div>
            {readiness && <div className={`alert mt-3 mb-0 py-2 ${readiness.status === 'blocked' ? 'alert-danger' : readiness.status === 'ready_with_limitations' ? 'alert-warning' : 'alert-success'}`}><strong>{readiness.status.replaceAll('_', ' ')}</strong>{readiness.limitations?.length ? <ul className="small mb-0">{readiness.limitations.map((item) => <li key={item}>{item.replaceAll('_', ' ')}</li>)}</ul> : null}</div>}
            <div className="d-flex justify-content-end mt-3"><button type="button" className="btn btn-outline-primary btn-sm" disabled={busy || selectedIds.length < 2} onClick={compare}>Compare selected ({selectedIds.length})</button></div>
            <div className="table-responsive mt-2"><table className="table table-sm align-middle mb-0"><thead><tr><th aria-label="Select for comparison" /><th>Created</th><th>Period</th><th>State</th><th>Status</th><th>Progress</th><th>Assumptions</th><th /></tr></thead><tbody>
                {runs.length === 0 && <tr><td colSpan="8" className="text-muted small">No Portfolio Replay runs.</td></tr>}
                {runs.map((run) => <tr key={run.id}><td><input type="checkbox" aria-label={`Compare Replay ${run.id}`} checked={selectedIds.includes(run.id)} disabled={!selectedIds.includes(run.id) && selectedIds.length >= 5} onChange={() => toggleComparison(run.id)} /></td><td>{new Date(run.created_at).toLocaleString()}</td><td>{run.period_start} – {run.period_end}</td><td>{run.starting_mode.replaceAll('_', ' ')}</td><td><span className="badge text-bg-secondary">{run.status}</span></td><td>{run.checkpoint_date || 'Queued'}</td><td>{run.price_method.replaceAll('_', ' ')}, {Number(run.adverse_slippage_percent)}%</td><td className="text-end"><button type="button" className="btn btn-outline-primary btn-sm me-1" disabled={busy} onClick={() => inspect(run.id)}>Details</button>{['queued', 'running'].includes(run.status) && <button type="button" className="btn btn-outline-warning btn-sm me-1" disabled={busy} onClick={() => cancel(run.id)}>Cancel</button>}<button type="button" className="btn btn-outline-danger btn-sm" disabled={busy} onClick={() => remove(run.id)}>Delete</button></td></tr>)}
            </tbody></table></div>
            {comparison && <div className={`alert mt-3 mb-0 ${comparison.compatible ? 'alert-info' : 'alert-warning'}`} data-testid="replay-comparison">
                <div className="d-flex justify-content-between"><strong>{comparison.compatible ? 'Compatible Replay comparison' : 'Replay assumptions differ materially'}</strong><button type="button" className="btn-close" aria-label="Close comparison" onClick={() => setComparison(null)} /></div>
                {comparison.assumption_differences?.length ? <div className="small">Different assumptions: {comparison.assumption_differences.map((item) => item.replaceAll('_', ' ')).join(', ')}</div> : null}
                <div className="row g-2 mt-1">{comparison.runs?.map((run) => <div className="col-md" key={run.id}><div className="border rounded bg-white p-2 small"><strong>Replay #{run.id}</strong><br />Return: {run.statistics?.return_percent ?? 'Unavailable'}%<br />TWR: {run.statistics?.twr_percent ?? 'Unavailable'}%<br />Drawdown: {run.statistics?.maximum_drawdown_percent ?? 'Unavailable'}%<br />Charges: {run.statistics?.simulated_charges ?? 'Unavailable'}<br />Transactions: {run.statistics?.transaction_count ?? 'Unavailable'}</div></div>)}</div>
                <div className="small mt-2">{comparison.disclosure}</div>
            </div>}
            {selected && <div className="border rounded p-3 mt-3" data-testid="replay-evidence-detail">
                <div className="d-flex justify-content-between"><strong>Replay evidence #{selected.id}</strong><button type="button" className="btn-close" aria-label="Close" onClick={() => setSelected(null)} /></div>
                <div className="small mt-2"><strong>Status:</strong> {selected.status}; <strong>checkpoints:</strong> {selected.evidence_summary?.checkpoint_count || 0}; <strong>transactions:</strong> {selected.evidence_summary?.transaction_count || 0}; <strong>recommendations:</strong> {selected.evidence_summary?.recommendation_count || 0}</div>
                <div className="small"><strong>Fill assumptions:</strong> {selected.price_method?.replaceAll('_', ' ')}, adverse slippage {Number(selected.adverse_slippage_percent)}%</div>
                {selected.results?.valuation && <div className="small"><strong>Ending value:</strong> {Number(selected.results.valuation.total_value || 0).toLocaleString()}</div>}
                {selected.results?.limitations?.length ? <div className="alert alert-warning py-2 mt-2 mb-0"><strong>Limitations</strong><ul className="small mb-0">{selected.results.limitations.map((item) => <li key={item}>{item.replaceAll('_', ' ')}</li>)}</ul></div> : null}
                {selected.evidence_summary?.latest_market_fingerprint && <div className="small text-muted text-break mt-2"><strong>Latest market fingerprint:</strong> {selected.evidence_summary.latest_market_fingerprint}</div>}
            </div>}
        </div>
    </div>;
}
