import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { appUrl } from '../appBase';

function statusBadgeClass(status) {
    switch (status) {
        case 'active':
            return 'text-bg-success';
        case 'draft':
            return 'text-bg-warning';
        case 'archived':
            return 'text-bg-secondary';
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
 * Strategy Artifact Registry — list / select / import / export / validate.
 * @param {{ adminMode?: boolean }} props
 */
export default function StrategyRegistryPage({ adminMode = false }) {
    const basePath = adminMode ? '/settings/strategy-registry' : '/strategy/registry';
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [origin, setOrigin] = useState('');
    const [importText, setImportText] = useState('');
    const [validateResult, setValidateResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const importValidated = Boolean(validateResult?.ok);

    const load = async () => {
        setLoading(true);
        setError('');
        try {
            const params = {};
            if (q.trim()) params.q = q.trim();
            if (status) params.status = status;
            if (origin) params.origin = origin;
            const [listRes, metaRes] = await Promise.all([
                api.get('/v1/strategy-registry', { params }),
                api.get('/v1/strategy-registry/meta'),
            ]);
            setRows(listRes.data?.data || []);
            setMeta(metaRes.data?.data || null);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Failed to load Strategy Registry');
            setRows([]);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, status, origin]);

    const countsLabel = useMemo(() => {
        if (!meta?.counts) return '';
        const c = meta.counts;
        return `${c.total} total · ${c.active} active · ${c.draft} draft · ${c.archived} archived`;
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
            const res = await api.post('/v1/strategy-registry/validate', envelope);
            setValidateResult(res.data?.data || null);
            setNotice(res.data?.data?.ok ? 'Validation passed.' : 'Validation reported issues.');
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Validate failed');
        } finally {
            setBusy(false);
        }
    };

    const onImport = async () => {
        if (!validateResult?.ok) {
            setError('Validate the JSON successfully before importing.');
            return;
        }
        setBusy(true);
        setNotice('');
        setError('');
        try {
            const envelope = parseImportPayload();
            const res = await api.post('/v1/strategy-registry/import', envelope);
            const created = res.data?.data;
            setNotice(`Imported “${created?.name || 'strategy'}” as draft (slug ${created?.slug || '—'}). Select it to make it active.`);
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
            const res = await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/export`);
            const env = res.data?.data;
            downloadJson(`${env?.slug || 'strategy'}.json`, env);
            setNotice(`Exported ${env?.slug || id} (portable Screener/Indicator refs only).`);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Export failed');
        } finally {
            setBusy(false);
        }
    };

    const onActivate = async (row) => {
        const id = row.artifact_id || row.slug;
        if (!window.confirm(`Select “${row.name}” as this portfolio’s only active strategy?`)) return;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/activate`);
            setNotice(`“${row.name}” is now the active strategy for this portfolio.`);
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Selection failed');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 className="h4 mb-1">{adminMode ? 'Strategy Registry (Admin)' : 'Strategy Registry'}</h2>
                    <p className="text-muted small mb-0">
                        Reusable Strategy definitions with metadata, versions, and JSON import/export.
                        Each portfolio selects exactly one active Strategy. Strategies reference Screeners by registry slug / factory key — they never embed Screener trees.
                    </p>
                    {countsLabel && <p className="text-muted small mb-0 mt-1">{countsLabel}</p>}
                </div>
                <div className="d-flex flex-wrap gap-2">
                    {!adminMode && (
                        <Link to="/strategy" className="btn btn-outline-secondary btn-sm">Back to Strategy editor</Link>
                    )}
                    {adminMode && (
                        <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">Back to settings</Link>
                    )}
                    <Link to="/strategy" className="btn btn-outline-primary btn-sm">Edit active Strategy</Link>
                </div>
            </div>

            <div className="card">
                <div className="card-body">
                    <div className="row g-2 align-items-end">
                        <div className="col-md-4">
                            <label className="form-label small mb-1" htmlFor="str-search">Search</label>
                            <input
                                id="str-search"
                                className="form-control form-control-sm"
                                placeholder="slug, name, intent"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <div className="col-md-2">
                            <label className="form-label small mb-1" htmlFor="str-status">Status</label>
                            <select
                                id="str-status"
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
                            <label className="form-label small mb-1" htmlFor="str-origin">Origin</label>
                            <select
                                id="str-origin"
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
                                <th>Status</th>
                                <th>Version</th>
                                <th>Selected</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr>
                                    <td colSpan={6} className="text-muted">Loading…</td>
                                </tr>
                            )}
                            {!loading && rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted">No strategies match the filters.</td>
                                </tr>
                            )}
                            {!loading && rows.map((row) => {
                                const m = row.metadata || {};
                                const id = row.artifact_id || row.slug;
                                const selected = !!m.is_selected || m.status === 'active';
                                return (
                                    <tr key={id}>
                                        <td>
                                            <Link to={`${basePath}/${encodeURIComponent(id)}`}>
                                                <code>{row.slug}</code>
                                            </Link>
                                        </td>
                                        <td>{row.name}</td>
                                        <td>
                                            <span className={`badge ${statusBadgeClass(m.status)}`}>{m.status || '—'}</span>
                                        </td>
                                        <td>{row.artifact_version}</td>
                                        <td>{selected ? 'Yes' : '—'}</td>
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
                                                {!selected && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-primary btn-sm"
                                                        disabled={busy}
                                                        onClick={() => onActivate(row)}
                                                    >
                                                        Select
                                                    </button>
                                                )}
                                                {selected && (
                                                    <Link to="/strategy" className="btn btn-outline-primary btn-sm">
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
                    <h3 className="h6 mb-0">Import / validate Strategy JSON</h3>
                    <p className="text-muted small mb-0">
                        Paste a Trading Artifact envelope (<code>artifact_type: strategy</code>).
                        Mandatory fields: <code>schema_version</code>, <code>artifact_type</code>, <code>slug</code>, <code>name</code>, <code>metadata</code>, and <code>definition.scoring_model</code> with enabled weights summing to 100.
                        Eligibility must reference Screeners by <code>screener_slug</code> / <code>screener_factory_key</code> only — never embed condition trees.
                        {' '}
                        <a href={appUrl('/docs/strategy-registry.html')} target="_blank" rel="noopener noreferrer">
                            Import schema guide
                        </a>
                        {' '}
                        (mandatory vs optional fields, scoring keys, minimal example).
                        {' '}
                        <a
                            href={appUrl('/docs/stox-trading-artifacts-ai-guide.md')}
                            download="stox-trading-artifacts-ai-guide.md"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Download AI authoring guide (.md)
                        </a>
                        {' '}
                        — full Indicator + Screener + Strategy + Cookbook pack for AI/offline use.
                        Run <strong>Validate</strong> first — <strong>Import</strong> stays disabled until validation succeeds.
                        Import creates a <strong>draft</strong> — use Select to activate it for this portfolio.
                    </p>
                    <textarea
                        className="form-control font-monospace small"
                        rows={10}
                        placeholder='{"schema_version":"1.0","artifact_type":"strategy","slug":"...", ...}'
                        value={importText}
                        onChange={(e) => {
                            setImportText(e.target.value);
                            setValidateResult(null);
                        }}
                    />
                    <div className="d-flex flex-wrap gap-2">
                        <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy || !importText.trim()} onClick={onValidate}>
                            Validate
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            disabled={busy || !importText.trim() || !importValidated}
                            title={importValidated ? 'Import validated JSON as draft' : 'Validate successfully before importing'}
                            onClick={onImport}
                        >
                            Import
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
