import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';

function statusBadgeClass(status) {
    switch (status) {
        case 'active':
            return 'text-bg-success';
        case 'draft':
            return 'text-bg-warning';
        case 'deprecated':
            return 'text-bg-dark';
        default:
            return 'text-bg-light';
    }
}

function downloadJson(filename, data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

/**
 * Screener Artifact Registry — list / import / export / validate.
 * @param {{ adminMode?: boolean }} props
 */
export default function ScreenerRegistryPage({ adminMode = false }) {
    const basePath = adminMode ? '/settings/screener-registry' : '/screeners/registry';
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [ownership, setOwnership] = useState('');
    const [origin, setOrigin] = useState('');
    const [importText, setImportText] = useState('');
    const [validateResult, setValidateResult] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        setLoading(true);
        setError('');
        try {
            const params = {};
            if (q.trim()) params.q = q.trim();
            if (status) params.status = status;
            if (ownership) params.ownership = ownership;
            if (origin) params.origin = origin;
            const [listRes, metaRes] = await Promise.all([
                api.get('/v1/screener-registry', { params }),
                api.get('/v1/screener-registry/meta'),
            ]);
            setRows(listRes.data?.data || []);
            setMeta(metaRes.data?.data || null);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Failed to load Screener Registry');
            setRows([]);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, status, ownership, origin]);

    const countsLabel = useMemo(() => {
        if (!meta?.counts) return '';
        const c = meta.counts;
        return `${c.own} in this portfolio · ${c.shared_available} shared available · ${c.factory} factory`;
    }, [meta]);

    const parseImportPayload = () => {
        const parsed = JSON.parse(importText);
        if (parsed && typeof parsed === 'object' && parsed.artifact && typeof parsed.artifact === 'object') {
            return parsed.artifact;
        }
        return parsed;
    };

    const onValidate = async () => {
        setBusy(true);
        setNotice('');
        setValidateResult(null);
        setError('');
        try {
            const envelope = parseImportPayload();
            const res = await api.post('/v1/screener-registry/validate', envelope);
            setValidateResult(res.data?.data || null);
            setNotice(res.data?.data?.ok ? 'Validation passed.' : 'Validation reported issues.');
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Validate failed');
        } finally {
            setBusy(false);
        }
    };

    const onImport = async () => {
        setBusy(true);
        setNotice('');
        setError('');
        try {
            const envelope = parseImportPayload();
            const res = await api.post('/v1/screener-registry/import', envelope);
            const created = res.data?.data;
            setNotice(`Imported “${created?.name || 'screener'}” (slug ${created?.slug || '—'}).`);
            setImportText('');
            setValidateResult(null);
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Import failed');
        } finally {
            setBusy(false);
        }
    };

    const onExport = async (row) => {
        setBusy(true);
        setError('');
        try {
            const id = row.artifact_id || row.slug;
            const res = await api.post(`/v1/screener-registry/${encodeURIComponent(id)}/export`);
            const env = res.data?.data;
            downloadJson(`${env?.slug || 'screener'}.json`, env);
            setNotice(`Exported ${env?.slug || id}.`);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Export failed');
        } finally {
            setBusy(false);
        }
    };

    const onImportShared = async (row) => {
        const sourceId = row.artifact_id || row.metadata?.legacy_id;
        if (!sourceId) return;
        setBusy(true);
        setError('');
        try {
            const res = await api.post(`/v1/screener-registry/shared/${encodeURIComponent(sourceId)}/import`);
            setNotice(`Copied shared screener into this portfolio as “${res.data?.data?.name || 'screener'}”.`);
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Shared import failed');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 className="h4 mb-1">{adminMode ? 'Screener Registry (Admin)' : 'Screener Registry'}</h2>
                    <p className="text-muted small mb-0">
                        Reusable Screener artifacts with metadata, versioning, and JSON import/export.
                        Execution still uses the existing Screener run engine — this registry does not redesign conditions.
                    </p>
                    {countsLabel && <p className="text-muted small mb-0 mt-1">{countsLabel}</p>}
                </div>
                <div className="d-flex flex-wrap gap-2">
                    {!adminMode && (
                        <Link to="/screeners" className="btn btn-outline-secondary btn-sm">Back to Screeners</Link>
                    )}
                    {adminMode && (
                        <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">Back to settings</Link>
                    )}
                    <Link to="/screeners" className="btn btn-outline-primary btn-sm">Open Screener editor</Link>
                </div>
            </div>

            <div className="card">
                <div className="card-body">
                    <div className="row g-2 align-items-end">
                        <div className="col-md-4">
                            <label className="form-label small mb-1" htmlFor="sr-search">Search</label>
                            <input
                                id="sr-search"
                                className="form-control form-control-sm"
                                placeholder="slug, name, intent"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="sr-status">Status</label>
                            <select
                                id="sr-status"
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
                            <label className="form-label small mb-1" htmlFor="sr-own">Ownership</label>
                            <select
                                id="sr-own"
                                className="form-select form-select-sm"
                                value={ownership}
                                onChange={(e) => setOwnership(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="own">This portfolio</option>
                                <option value="shared">Shared available</option>
                            </select>
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="sr-origin">Origin</label>
                            <select
                                id="sr-origin"
                                className="form-select form-select-sm"
                                value={origin}
                                onChange={(e) => setOrigin(e.target.value)}
                            >
                                <option value="">All</option>
                                {(meta?.origins || []).map((o) => (
                                    <option key={o.id} value={o.id}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-2">
                            <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm w-100"
                                onClick={() => {
                                    setQ('');
                                    setStatus('');
                                    setOwnership('');
                                    setOrigin('');
                                }}
                            >
                                Clear filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {error && <div className="alert alert-danger mb-0">{error}</div>}
            {notice && <div className="alert alert-success mb-0">{notice}</div>}

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Slug</th>
                                <th>Name</th>
                                <th>Ownership</th>
                                <th>Status</th>
                                <th>Version</th>
                                <th>Universe</th>
                                <th>Actions</th>
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
                                    <td colSpan={7} className="text-muted">No screeners match the filters.</td>
                                </tr>
                            )}
                            {!loading && rows.map((row) => {
                                const m = row.metadata || {};
                                const isShared = m.ownership === 'shared';
                                const id = row.artifact_id || row.slug;
                                return (
                                    <tr key={`${m.ownership || 'own'}-${id}`}>
                                        <td>
                                            <Link to={`${basePath}/${encodeURIComponent(id)}`}>
                                                <code>{row.slug}</code>
                                            </Link>
                                        </td>
                                        <td>{row.name}</td>
                                        <td className="text-capitalize">{m.ownership || 'own'}</td>
                                        <td>
                                            <span className={`badge ${statusBadgeClass(m.status)}`}>{m.status || '—'}</span>
                                        </td>
                                        <td>{row.artifact_version}</td>
                                        <td><code className="small">{m.universe || '—'}</code></td>
                                        <td>
                                            <div className="d-flex flex-wrap gap-1">
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-secondary btn-sm"
                                                    disabled={busy}
                                                    onClick={() => onExport(row)}
                                                >
                                                    Export
                                                </button>
                                                {isShared && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-outline-primary btn-sm"
                                                        disabled={busy}
                                                        onClick={() => onImportShared(row)}
                                                    >
                                                        Import copy
                                                    </button>
                                                )}
                                                {!isShared && (
                                                    <Link
                                                        to={`/screeners/${encodeURIComponent(id)}`}
                                                        className="btn btn-outline-primary btn-sm"
                                                    >
                                                        Edit
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="card">
                <div className="card-body d-grid gap-2">
                    <h3 className="h6 mb-0">Import / validate Screener JSON</h3>
                    <p className="text-muted small mb-0">
                        Paste a Trading Artifact envelope (<code>artifact_type: screener</code>) following the approved JSON specification.
                        Validate before import. Import always creates a new screener in this portfolio.
                    </p>
                    <textarea
                        className="form-control font-monospace small"
                        rows={10}
                        placeholder='{"schema_version":"1.0","artifact_type":"screener","slug":"...", ...}'
                        value={importText}
                        onChange={(e) => setImportText(e.target.value)}
                    />
                    <div className="d-flex flex-wrap gap-2">
                        <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy || !importText.trim()} onClick={onValidate}>
                            Validate
                        </button>
                        <button type="button" className="btn btn-primary btn-sm" disabled={busy || !importText.trim()} onClick={onImport}>
                            Create from JSON
                        </button>
                    </div>
                    {validateResult && (
                        <pre className="bg-light border rounded p-2 small mb-0 overflow-auto" style={{ maxHeight: 240 }}>
                            {JSON.stringify(validateResult, null, 2)}
                        </pre>
                    )}
                </div>
            </div>
        </div>
    );
}
