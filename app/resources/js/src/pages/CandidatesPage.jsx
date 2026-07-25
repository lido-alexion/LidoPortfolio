import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

export default function CandidatesPage() {
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [source, setSource] = useState('');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(null);
    const [running, setRunning] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const params = {};
            if (source) params.source = source;
            if (search.trim()) params.search = search.trim();
            const { data } = await api.get('/v1/candidates', { params });
            setItems(Array.isArray(data?.data) ? data.data : []);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load candidates', 'danger');
        } finally {
            setLoading(false);
        }
    }, [source, search]);

    useEffect(() => {
        load();
    }, [load]);

    const sources = useMemo(() => {
        const set = new Set(items.map((i) => i.source).filter(Boolean));
        return Array.from(set).sort();
    }, [items]);

    const runDiscovery = async () => {
        setRunning(true);
        try {
            await api.post('/v1/discovery/runs');
            showToast('Discovery run completed', 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Discovery failed', 'danger');
        } finally {
            setRunning(false);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Discovery</h1>
                    <p className="text-muted small mb-0">
                        Discovery output — patterns, screener hits, or holdings/watchlist membership.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading || running}>Refresh</button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={runDiscovery} disabled={running}>
                        {running ? 'Running…' : 'Run discovery'}
                    </button>
                </div>
            </div>

            <div className="row g-2 mb-3">
                <div className="col-md-4">
                    <input
                        className="form-control form-control-sm"
                        placeholder="Search symbol or name"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <div className="col-md-3">
                    <select className="form-select form-select-sm" value={source} onChange={(e) => setSource(e.target.value)}>
                        <option value="">All sources</option>
                        {sources.map((s) => <option key={s} value={s}>{s}</option>)}
                        {!sources.includes('pattern') && <option value="pattern">pattern</option>}
                        {!sources.includes('screener') && <option value="screener">screener</option>}
                        {!sources.includes('holding') && <option value="holding">holding</option>}
                        {!sources.includes('watchlist') && <option value="watchlist">watchlist</option>}
                    </select>
                </div>
            </div>

            {loading ? <p className="text-muted">Loading…</p> : items.length === 0 ? (
                <div className="border rounded p-4 text-muted">No candidates yet. Run discovery or the full decision pipeline.</div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Source</th>
                                <th>Discovery reason</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((c) => (
                                <tr key={c.id}>
                                    <td>
                                        <strong>{c.symbol}</strong>
                                        {c.name ? <div className="small text-muted">{c.name}</div> : null}
                                    </td>
                                    <td><span className="badge text-bg-light">{c.source}</span></td>
                                    <td className="small">{c.discovery_reason || '—'}</td>
                                    <td className="text-nowrap">
                                        <button type="button" className="btn btn-link btn-sm px-0 me-2" onClick={() => setSelected(c)}>Evidence</button>
                                        <Link className="btn btn-link btn-sm px-0" to="/evaluations">Evaluation</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {selected && (
                <div className="modal show d-block" style={{ background: 'rgba(0,0,0,.45)' }}>
                    <div className="modal-dialog modal-lg modal-dialog-scrollable">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">{selected.symbol} — discovery evidence</h5>
                                <button type="button" className="btn-close" onClick={() => setSelected(null)} aria-label="Close" />
                            </div>
                            <div className="modal-body">
                                <pre className="small bg-body-tertiary p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                    {JSON.stringify(selected.evidence || {}, null, 2)}
                                </pre>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
