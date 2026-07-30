import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { appUrl } from '../appBase';

function statusBadgeClass(status) {
    switch (status) {
        case 'active':
            return 'text-bg-success';
        case 'stub':
            return 'text-bg-warning';
        case 'planned':
            return 'text-bg-secondary';
        case 'deprecated':
            return 'text-bg-dark';
        default:
            return 'text-bg-light';
    }
}

export default function IndicatorRegistryPage() {
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [q, setQ] = useState('');
    const [category, setCategory] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoading(true);
            setError('');
            try {
                const params = {};
                if (q.trim()) params.q = q.trim();
                if (category) params.category = category;
                if (type) params.type = type;
                if (status) params.status = status;
                const [listRes, metaRes] = await Promise.all([
                    api.get('/v1/indicators', { params }),
                    api.get('/v1/indicators/meta'),
                ]);
                if (cancelled) return;
                setRows(listRes.data?.data || []);
                setMeta(metaRes.data?.data || null);
            } catch (err) {
                if (!cancelled) {
                    setError(err?.response?.data?.error?.message || err.message || 'Failed to load indicators');
                    setRows([]);
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => { cancelled = true; };
    }, [q, category, type, status]);

    const countsLabel = useMemo(() => {
        if (!meta?.counts) return '';
        const c = meta.counts;
        return `${c.total} total · ${c.primary} primary · ${c.composite} composite · ${c.metric} metric`;
    }, [meta]);

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 className="h4 mb-1">Indicator Registry</h2>
                    <p className="text-muted small mb-0">
                        Read-only catalogue of Primary, Composite, and Metric indicators. Formula explanations are documentation only — there is no formula editor.
                        {' '}
                        <a href={appUrl('/docs/indicator-registry.html')} target="_blank" rel="noopener noreferrer">
                            Catalogue guide
                        </a>
                        {' '}
                        (definitions, defaults, ranges, Screener vs Strategy usage).
                    </p>
                    {countsLabel && <p className="text-muted small mb-0 mt-1">{countsLabel}</p>}
                </div>
                <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">Back to settings</Link>
            </div>

            <div className="card">
                <div className="card-body">
                    <div className="row g-2 align-items-end">
                        <div className="col-md-4">
                            <label className="form-label small mb-1" htmlFor="ir-search">Search</label>
                            <input
                                id="ir-search"
                                className="form-control form-control-sm"
                                placeholder="id, name, or description"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="ir-category">Category</label>
                            <select
                                id="ir-category"
                                className="form-select form-select-sm"
                                value={category}
                                onChange={(e) => setCategory(e.target.value)}
                            >
                                <option value="">All</option>
                                {(meta?.categories || []).map((c) => (
                                    <option key={c.id} value={c.id}>{c.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="ir-type">Type</label>
                            <select
                                id="ir-type"
                                className="form-select form-select-sm"
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                            >
                                <option value="">All</option>
                                {(meta?.types || []).map((t) => (
                                    <option key={t.id} value={t.id}>{t.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="ir-status">Status</label>
                            <select
                                id="ir-status"
                                className="form-select form-select-sm"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                            >
                                <option value="">All</option>
                                {(meta?.statuses || []).map((s) => (
                                    <option key={s.id} value={s.id}>{s.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-2">
                            <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm w-100"
                                onClick={() => {
                                    setQ('');
                                    setCategory('');
                                    setType('');
                                    setStatus('');
                                }}
                            >
                                Clear filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {error && <div className="alert alert-danger mb-0">{error}</div>}

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Version</th>
                                <th>Screenable</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr>
                                    <td colSpan={7} className="text-muted">Loading…</td>
                                </tr>
                            )}
                            {!loading && rows.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted">No indicators match the filters.</td>
                                </tr>
                            )}
                            {!loading && rows.map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        <Link to={`/settings/indicators/${encodeURIComponent(row.id)}`}>
                                            <code>{row.id}</code>
                                        </Link>
                                    </td>
                                    <td>{row.display_name}</td>
                                    <td className="text-capitalize">{row.type}</td>
                                    <td>{row.category_label || row.category}</td>
                                    <td>
                                        <span className={`badge ${statusBadgeClass(row.status)}`}>{row.status}</span>
                                    </td>
                                    <td>{row.version}</td>
                                    <td>{row.screenable ? 'Yes' : 'No'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
