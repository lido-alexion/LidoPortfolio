import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

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
    const [busy, setBusy] = useState(false);
    const [showCreate, setShowCreate] = useState(false);
    const [draft, setDraft] = useState({ type: 'screener', slug: '', name: '', semver: '1.0.0', ai_assisted: false, content: '' });
    const [packageText, setPackageText] = useState('');

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

    const createDraft = async () => {
        setBusy(true); setError('');
        try {
            await api.post('/v1/artifact-library/drafts', { ...draft, content: JSON.parse(draft.content) });
            setDraft({ type: 'screener', slug: '', name: '', semver: '1.0.0', ai_assisted: false, content: '' });
            setShowCreate(false);
            showToast('Draft created. It remains unpublished until explicitly validated and published.');
            const response = await api.get('/v1/artifact-library');
            setRows(response.data?.data || []);
        } catch (requestError) { setError(requestError instanceof SyntaxError ? 'Draft content must be valid JSON.' : getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    const importPackage = async () => {
        setBusy(true); setError('');
        try {
            const response = await api.post('/v1/artifact-library/import', { package: JSON.parse(packageText) });
            setPackageText('');
            showToast(`Imported ${response.data?.meta?.count || 0} validated Drafts. Nothing was published or deployed.`);
            const refreshed = await api.get('/v1/artifact-library');
            setRows(refreshed.data?.data || []);
        } catch (requestError) { setError(requestError instanceof SyntaxError ? 'Package must be valid JSON.' : getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between gap-2">
                <div>
                <h2 className="h4 mb-1">Trading Artifact Library</h2>
                <p className="text-muted small mb-0">
                    Immutable Strategies, Screeners, and Bundles available to this account. Deployment remains Portfolio-specific and version-pinned.
                </p>
                </div>
                <button type="button" className="btn btn-sm btn-primary align-self-start" onClick={() => setShowCreate((shown) => !shown)}>Create Draft</button>
            </div>

            {showCreate && <div className="card"><div className="card-body d-grid gap-2">
                <h3 className="h6 mb-0">Create unpublished Draft</h3>
                <div className="row g-2">
                    <div className="col-md-3"><label className="form-label small">Type<select className="form-select" value={draft.type} onChange={(event) => setDraft({ ...draft, type: event.target.value })}><option value="screener">Screener</option><option value="strategy">Strategy</option><option value="bundle">Bundle</option></select></label></div>
                    <div className="col-md-3"><label className="form-label small">Slug<input className="form-control" value={draft.slug} onChange={(event) => setDraft({ ...draft, slug: event.target.value })} /></label></div>
                    <div className="col-md-4"><label className="form-label small">Name<input className="form-control" value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} /></label></div>
                    <div className="col-md-2"><label className="form-label small">Version<input className="form-control" value={draft.semver} onChange={(event) => setDraft({ ...draft, semver: event.target.value })} /></label></div>
                </div>
                <label className="form-label small mb-0">Artifact envelope JSON<textarea className="form-control font-monospace" rows="10" value={draft.content} onChange={(event) => setDraft({ ...draft, content: event.target.value })} /></label>
                <label className="form-check"><input className="form-check-input" type="checkbox" checked={draft.ai_assisted} onChange={(event) => setDraft({ ...draft, ai_assisted: event.target.checked })} /><span className="form-check-label small">AI assisted this Draft (AI cannot publish or deploy it)</span></label>
                <div><button type="button" className="btn btn-primary btn-sm" onClick={createDraft} disabled={busy || !draft.slug || !draft.name || !draft.content}>Create Draft</button></div>
            </div></div>}

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

            <details className="card"><summary className="card-header">Import validated portable package</summary><div className="card-body d-grid gap-2">
                <p className="small text-muted mb-0">The whole package is checksum- and dependency-validated before any rows are written. Foreign artifacts become local Draft lineages.</p>
                <textarea className="form-control font-monospace" rows="8" value={packageText} onChange={(event) => setPackageText(event.target.value)} placeholder="Paste stox.reusable_artifacts.v1 JSON" />
                <div><button type="button" className="btn btn-sm btn-outline-primary" disabled={busy || !packageText.trim()} onClick={importPackage}>Validate and import as Drafts</button></div>
            </div></details>
        </div>
    );
}
