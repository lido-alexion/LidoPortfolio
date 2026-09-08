import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';

const badge = (value) => ({
    owner: 'text-bg-primary', shared: 'text-bg-info', system: 'text-bg-secondary',
    usable: 'text-bg-success', warning: 'text-bg-warning', blocked: 'text-bg-danger',
}[value] || 'text-bg-light');

export default function ArtifactLibraryPage() {
    const [rows, setRows] = useState([]);
    const [q, setQ] = useState('');
    const [type, setType] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let cancelled = false;
        const timer = setTimeout(async () => {
            setLoading(true);
            setError('');
            try {
                const response = await api.get('/v1/artifact-library', { params: { q: q || undefined, type: type || undefined } });
                if (!cancelled) setRows(response.data?.data || []);
            } catch (requestError) {
                if (!cancelled) setError(getApiErrorMessage(requestError, 'Failed to load the Artifact Library.'));
            } finally {
                if (!cancelled) setLoading(false);
            }
        }, 180);
        return () => { cancelled = true; clearTimeout(timer); };
    }, [q, type]);

    return (
        <div className="d-grid gap-3">
            <div>
                <h2 className="h4 mb-1">Trading Artifact Library</h2>
                <p className="text-muted small mb-0">
                    Immutable Strategies, Screeners, and Bundles available to this account. Deployment remains Portfolio-specific and version-pinned.
                </p>
            </div>

            <div className="card"><div className="card-body row g-2">
                <div className="col-md-8">
                    <label className="form-label small" htmlFor="artifact-library-search">Search</label>
                    <input id="artifact-library-search" className="form-control" value={q} onChange={(event) => setQ(event.target.value)} placeholder="Name or slug" />
                </div>
                <div className="col-md-4">
                    <label className="form-label small" htmlFor="artifact-library-type">Type</label>
                    <select id="artifact-library-type" className="form-select" value={type} onChange={(event) => setType(event.target.value)}>
                        <option value="">All types</option>
                        <option value="strategy">Strategies</option>
                        <option value="screener">Screeners</option>
                        <option value="bundle">Bundles</option>
                        <option value="indicator">Indicators</option>
                    </select>
                </div>
            </div></div>

            {error && <div className="alert alert-danger mb-0">{error}</div>}
            {loading && <div className="text-muted">Loading Library…</div>}
            {!loading && rows.length === 0 && <div className="card"><div className="card-body text-muted">No accessible artifacts match these filters.</div></div>}
            {!loading && rows.length > 0 && (
                <div className="card"><div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Artifact</th><th>Access</th><th>Versions</th><th>Portfolio binding</th><th /></tr></thead>
                        <tbody>{rows.map((row) => (
                            <tr key={row.artifact_uuid}>
                                <td>
                                    <div className="fw-semibold">{row.name}</div>
                                    <div className="small text-muted"><code>{row.slug}</code> · {row.type}</div>
                                </td>
                                <td><span className={`badge ${badge(row.permission)}`}>{row.permission}</span><div className="small text-muted mt-1">{row.origin}</div></td>
                                <td>
                                    <div>{row.latest_published_version ? `Published ${row.latest_published_version}` : 'No publication'}</div>
                                    {row.draft_version && <div className="small text-warning">Draft {row.draft_version}</div>}
                                </td>
                                <td>{row.portfolio_binding ? (
                                    <><span className={`badge ${badge(row.portfolio_binding.usability_state)}`}>{row.portfolio_binding.usability_state}</span><div className="small mt-1">{row.portfolio_binding.status} · {row.portfolio_binding.active_version}</div></>
                                ) : <span className="text-muted">Not bound</span>}</td>
                                <td className="text-end"><Link className="btn btn-sm btn-outline-primary" to={`/artifact-library/${row.artifact_uuid}`}>Inspect</Link></td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div></div>
            )}
        </div>
    );
}
