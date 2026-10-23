import React, { useCallback, useEffect, useRef, useState } from 'react';
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
    const portfolioScope = portfolio?.id == null ? null : String(portfolio.id);
    const currentScopeRef = useRef(portfolioScope);
    currentScopeRef.current = portfolioScope;
    const requestGeneration = useRef(0);
    const today = new Date();
    const prior = new Date(today);
    prior.setUTCFullYear(prior.getUTCFullYear() - 1);
    const [form, setForm] = useState({
        starting_mode: 'new_simulated', period_start: isoDate(prior), period_end: isoDate(today),
        starting_cash: '100000', price_method: 'next_open', adverse_slippage_percent: '0', strategy_version_overrides: {},
    });
    const [runList, setRunList] = useState({ scope: portfolioScope, status: 'loading', runs: [] });
    const [readiness, setReadiness] = useState(null);
    const [selected, setSelected] = useState(null);
    const [selectedScope, setSelectedScope] = useState(portfolioScope);
    const [selectedIds, setSelectedIds] = useState([]);
    const [comparison, setComparison] = useState(null);
    const [comparisonScope, setComparisonScope] = useState(portfolioScope);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (portfolioScope == null || currentScopeRef.current !== portfolioScope) return false;
        const scope = portfolioScope;
        const generation = ++requestGeneration.current;
        const isCurrentRequest = () => requestGeneration.current === generation && currentScopeRef.current === scope;
        setRunList((current) => ({
            scope,
            status: 'loading',
            runs: current.scope === scope ? current.runs : [],
        }));
        try {
            const response = await api.get('/replays');
            const nextRuns = response.data?.data;
            if (!Array.isArray(nextRuns)) throw new Error('Invalid replay history response');
            if (!isCurrentRequest()) return false;
            setRunList({ scope, status: 'confirmed', runs: nextRuns });
            return true;
        } catch (error) {
            if (!isCurrentRequest()) return false;
            setRunList((current) => ({
                scope,
                status: 'unavailable',
                runs: current.scope === scope ? current.runs : [],
            }));
            showToast(getApiErrorMessage(error), 'danger');
            return false;
        }
    }, [portfolioScope]);

    useEffect(() => {
        setRunList((current) => current.scope === portfolioScope
            ? current
            : { scope: portfolioScope, status: 'loading', runs: [] });
        setSelected(null);
        setSelectedScope(portfolioScope);
        setSelectedIds([]);
        setComparison(null);
        setComparisonScope(portfolioScope);
        load();
        return () => { requestGeneration.current += 1; };
    }, [load, portfolioScope]);

    const currentRunList = runList.scope === portfolioScope
        ? runList
        : { scope: portfolioScope, status: 'loading', runs: [] };
    const runs = currentRunList.runs;
    const currentSelected = selectedScope === portfolioScope ? selected : null;
    const currentComparison = comparisonScope === portfolioScope ? comparison : null;
    const currentSelectedIds = runList.scope === portfolioScope ? selectedIds : [];

    const payload = () => ({
        ...form,
        ...(form.starting_mode === 'new_simulated' ? { starting_cash: Number(form.starting_cash) } : { starting_cash: null }),
        adverse_slippage_percent: Number(form.adverse_slippage_percent || 0),
        ...(form.starting_mode === 'historical_branch' ? { strategy_version_overrides: form.strategy_version_overrides } : {}),
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
        const scope = portfolioScope;
        const generation = requestGeneration.current;
        setBusy(true);
        try {
            const response = await api.get(`/replays/${id}`);
            if (currentScopeRef.current === scope && requestGeneration.current === generation) {
                setSelected(response.data?.data || null);
                setSelectedScope(scope);
            }
        }
        catch (error) {
            if (currentScopeRef.current === scope && requestGeneration.current === generation) showToast(getApiErrorMessage(error), 'danger');
        }
        finally { setBusy(false); }
    };

    const compare = async () => {
        const scope = portfolioScope;
        const generation = requestGeneration.current;
        setBusy(true);
        try {
            const response = await api.post('/replays/compare', { run_ids: selectedIds });
            if (currentScopeRef.current === scope && requestGeneration.current === generation) {
                setComparison(response.data?.data || null);
                setComparisonScope(scope);
            }
        }
        catch (error) {
            if (currentScopeRef.current === scope && requestGeneration.current === generation) showToast(getApiErrorMessage(error), 'danger');
        }
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
    const updateOverride = (bindingId, versionId) => update('strategy_version_overrides', {
        ...form.strategy_version_overrides,
        ...(versionId ? { [bindingId]: Number(versionId) } : (() => {
            const next = { ...form.strategy_version_overrides };
            delete next[bindingId];
            return next;
        })()),
    });

    return <div className="card mb-4 portfolio-replay-panel" role="region" aria-label="Portfolio Replay">
        <div className="card-body">
            <h3 className="h6 mb-1">Portfolio Replay</h3>
            <p className="small text-muted">Historical, deterministic Portfolio simulation. A Replay is isolated from <strong>{portfolio.name}</strong> and cannot be edited after it starts.</p>
            <div className="row g-2 align-items-end">
                <div className="col-md-2"><label className="form-label small" htmlFor="replay-starting-mode">Starting state</label><select id="replay-starting-mode" className="form-select form-select-sm" value={form.starting_mode} onChange={(e) => update('starting_mode', e.target.value)}><option value="new_simulated">New simulation</option><option value="historical_branch">Historical branch</option></select></div>
                <div className="col-md-2"><label className="form-label small" htmlFor="replay-from">From</label><input id="replay-from" type="date" className="form-control form-control-sm" value={form.period_start} onChange={(e) => update('period_start', e.target.value)} /></div>
                <div className="col-md-2"><label className="form-label small" htmlFor="replay-to">To</label><input id="replay-to" type="date" max={isoDate(today)} className="form-control form-control-sm" value={form.period_end} onChange={(e) => update('period_end', e.target.value)} /></div>
                {form.starting_mode === 'new_simulated' && <div className="col-md-2"><label className="form-label small" htmlFor="replay-starting-cash">Starting cash</label><input id="replay-starting-cash" type="number" min="0.01" className="form-control form-control-sm" value={form.starting_cash} onChange={(e) => update('starting_cash', e.target.value)} /></div>}
                <div className="col-md-2"><label className="form-label small" htmlFor="replay-fill-method">Fill method</label><select id="replay-fill-method" className="form-select form-select-sm" value={form.price_method} onChange={(e) => update('price_method', e.target.value)}>{METHODS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                <div className="col-md-1"><label className="form-label small" htmlFor="replay-slippage">Slippage %</label><input id="replay-slippage" type="number" min="0" max="100" step="0.01" className="form-control form-control-sm" value={form.adverse_slippage_percent} onChange={(e) => update('adverse_slippage_percent', e.target.value)} /></div>
                <div className="col-md-1 d-flex gap-1"><button type="button" className="btn btn-outline-primary btn-sm" disabled={busy} onClick={assess}>Check</button><button type="button" className="btn btn-primary btn-sm" disabled={busy || readiness?.status === 'blocked'} onClick={start}>Start</button></div>
            </div>
            {readiness && <div className={`alert mt-3 mb-0 py-2 ${readiness.status === 'blocked' ? 'alert-danger' : readiness.status === 'ready_with_limitations' ? 'alert-warning' : 'alert-success'}`}><strong>{readiness.status.replaceAll('_', ' ')}</strong>{readiness.limitations?.length ? <ul className="small mb-0">{readiness.limitations.map((item) => <li key={item}>{item.replaceAll('_', ' ')}</li>)}</ul> : null}</div>}
            {form.starting_mode === 'historical_branch' && readiness?.pinned_world?.binding_revisions?.length ? <div className="border rounded p-2 mt-3" data-testid="replay-counterfactual-options">
                <strong className="small">Strategy versions</strong>
                <div className="small text-muted mb-2">Historical Replay uses the versions active on the branch date. Optionally select a different published version for a counterfactual run.</div>
                {readiness.pinned_world.binding_revisions.map((binding) => {
                    const options = readiness.counterfactual_options?.[String(binding.binding_id)] || [];
                    const selectedVersion = binding.selected_artifact_version_id || binding.artifact_version_id;
                    return <div className="row g-2 align-items-center mb-1" key={binding.binding_id}>
                        <div className="col-md-5 small">{binding.strategy_name || `Strategy ${binding.strategy_id}`}<br /><span className="text-muted">Historical version {options.find((option) => option.artifact_version_id === binding.historical_artifact_version_id)?.semver || binding.historical_artifact_version_id}</span></div>
                        <div className="col-md-7"><label className="visually-hidden" htmlFor={`replay-version-${binding.binding_id}`}>Version for {binding.strategy_name || `Strategy ${binding.strategy_id}`}</label><select id={`replay-version-${binding.binding_id}`} className="form-select form-select-sm" value={form.strategy_version_overrides?.[binding.binding_id] || (binding.is_counterfactual ? selectedVersion : '')} onChange={(event) => updateOverride(binding.binding_id, event.target.value)}><option value="">Use historical version</option>{options.map((option) => <option key={option.artifact_version_id} value={option.artifact_version_id}>{option.name} {option.semver}{option.is_historical ? ' (historical)' : ''}</option>)}</select></div>
                    </div>;
                })}
            </div> : null}
            <div className="d-flex justify-content-end mt-3"><button type="button" className="btn btn-outline-primary btn-sm" disabled={busy || currentSelectedIds.length < 2} onClick={compare}>Compare selected ({currentSelectedIds.length})</button></div>
            {currentRunList.status === 'loading' && runs.length === 0 && <p className="small text-muted mt-2 mb-0" role="status">Loading replay history…</p>}
            {currentRunList.status === 'loading' && runs.length > 0 && <p className="small text-muted mt-2 mb-0" role="status">Refreshing replay history. Showing the last successfully loaded list.</p>}
            {currentRunList.status === 'unavailable' && <div className="alert alert-warning mt-2 mb-0" role="alert">
                <p className="mb-2">{runs.length > 0
                    ? 'Could not refresh replay history. Showing the last successfully loaded list; it may be out of date.'
                    : 'Replay history could not be loaded. Retry to check saved runs.'}</p>
                <button type="button" className="btn btn-outline-primary btn-sm" disabled={busy} onClick={load}>Retry replay history</button>
            </div>}
            <div className="table-responsive mt-2"><table className="table table-sm align-middle mb-0"><thead><tr><th aria-label="Select for comparison" /><th>Created</th><th>Period</th><th>State</th><th>Status</th><th>Progress</th><th>Assumptions</th><th /></tr></thead><tbody>
                {currentRunList.status === 'confirmed' && runs.length === 0 && <tr><td colSpan="8" className="text-muted small">No Portfolio Replay runs.</td></tr>}
                {runs.map((run) => <tr key={run.id}><td><input type="checkbox" aria-label={`Compare Replay ${run.id}`} checked={currentSelectedIds.includes(run.id)} disabled={!currentSelectedIds.includes(run.id) && currentSelectedIds.length >= 5} onChange={() => toggleComparison(run.id)} /></td><td>{new Date(run.created_at).toLocaleString()}</td><td>{run.period_start} – {run.period_end}</td><td>{run.starting_mode.replaceAll('_', ' ')}</td><td><span className="badge text-bg-secondary">{run.status}</span></td><td>{run.checkpoint_date || 'Queued'}</td><td>{run.price_method.replaceAll('_', ' ')}, {Number(run.adverse_slippage_percent)}%</td><td className="text-end"><button type="button" className="btn btn-outline-primary btn-sm me-1" disabled={busy} onClick={() => inspect(run.id)}>Details</button>{['queued', 'running'].includes(run.status) && <button type="button" className="btn btn-outline-warning btn-sm me-1" disabled={busy} onClick={() => cancel(run.id)}>Cancel</button>}<button type="button" className="btn btn-outline-danger btn-sm" disabled={busy} onClick={() => remove(run.id)}>Delete</button></td></tr>)}
            </tbody></table></div>
            {currentComparison && <div className={`alert mt-3 mb-0 ${currentComparison.compatible ? 'alert-info' : 'alert-warning'}`} data-testid="replay-comparison">
                <div className="d-flex justify-content-between"><strong>{currentComparison.compatible ? 'Compatible Replay comparison' : 'Replay assumptions differ materially'}</strong><button type="button" className="btn-close" aria-label="Close comparison" onClick={() => setComparison(null)} /></div>
                {currentComparison.assumption_differences?.length ? <div className="small">Different assumptions: {currentComparison.assumption_differences.map((item) => item.replaceAll('_', ' ')).join(', ')}</div> : null}
                <div className="row g-2 mt-1">{currentComparison.runs?.map((run) => <div className="col-md" key={run.id}><div className="border rounded text-bg-light p-2 small"><strong>Replay #{run.id}</strong><br />Return: {run.statistics?.return_percent ?? 'Unavailable'}%<br />TWR: {run.statistics?.twr_percent ?? 'Unavailable'}%<br />Drawdown: {run.statistics?.maximum_drawdown_percent ?? 'Unavailable'}%<br />Charges: {run.statistics?.simulated_charges ?? 'Unavailable'}<br />Transactions: {run.statistics?.transaction_count ?? 'Unavailable'}</div></div>)}</div>
                <div className="small mt-2">{currentComparison.disclosure}</div>
            </div>}
            {currentSelected && <div className="border rounded p-3 mt-3" data-testid="replay-evidence-detail">
                <div className="d-flex justify-content-between"><strong>Replay evidence #{currentSelected.id}</strong><button type="button" className="btn-close" aria-label="Close" onClick={() => setSelected(null)} /></div>
                <div className="small mt-2"><strong>Status:</strong> {currentSelected.status}; <strong>checkpoints:</strong> {currentSelected.evidence_summary?.checkpoint_count || 0}; <strong>transactions:</strong> {currentSelected.evidence_summary?.transaction_count || 0}; <strong>recommendations:</strong> {currentSelected.evidence_summary?.recommendation_count || 0}</div>
                <div className="small"><strong>Fill assumptions:</strong> {currentSelected.price_method?.replaceAll('_', ' ')}, adverse slippage {Number(currentSelected.adverse_slippage_percent)}%</div>
                {currentSelected.pinned_world?.counterfactual?.is_counterfactual ? <div className="alert alert-info py-2 mt-2 mb-0"><strong>Counterfactual Strategy versions</strong>{currentSelected.pinned_world.counterfactual.overrides?.map((override) => <div className="small" key={override.binding_id}>{override.strategy_name || `Strategy ${override.strategy_id}`}: historical {override.historical_semver || override.historical_artifact_version_id} -&gt; used {override.selected_semver || override.selected_artifact_version_id}</div>)}</div> : null}
                {currentSelected.results?.valuation && <div className="small"><strong>Ending value:</strong> {Number(currentSelected.results.valuation.total_value || 0).toLocaleString()}</div>}
                {currentSelected.results?.limitations?.length ? <div className="alert alert-warning py-2 mt-2 mb-0"><strong>Limitations</strong><ul className="small mb-0">{currentSelected.results.limitations.map((item) => <li key={item}>{item.replaceAll('_', ' ')}</li>)}</ul></div> : null}
                {currentSelected.evidence_summary?.latest_market_fingerprint && <div className="small text-muted text-break mt-2"><strong>Latest market fingerprint:</strong> {currentSelected.evidence_summary.latest_market_fingerprint}</div>}
            </div>}
        </div>
    </div>;
}
