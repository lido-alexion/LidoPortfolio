import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

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
 * @param {{ adminMode?: boolean }} props
 */
export default function StrategyRegistryDetailPage({ adminMode = false }) {
    const { id } = useParams();
    const basePath = adminMode ? '/settings/strategy-registry' : '/strategy/registry';
    const [env, setEnv] = useState(null);
    const [versions, setVersions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const load = async () => {
        setLoading(true);
        setError('');
        try {
            const showRes = await api.get(`/v1/strategy-registry/${encodeURIComponent(id)}`);
            const data = showRes.data?.data || null;
            setEnv(data);
            const verRes = await api.get(`/v1/strategy-registry/${encodeURIComponent(id)}/versions`);
            setVersions(verRes.data?.data || []);
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Failed to load strategy');
            setEnv(null);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    const onExport = async () => {
        setError('');
        try {
            const res = await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/export`);
            const data = res.data?.data;
            downloadJson(`${data?.slug || id}.json`, data);
            showToast('Exported portable JSON (no portfolio-local Screener ids).');
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Export failed');
        }
    };

    const onActivate = async () => {
        if (!window.confirm(`Enable “${env?.name || id}” for this portfolio? Other enabled strategies stay enabled.`)) return;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/activate`);
            showToast('Strategy enabled for this portfolio.');
            await load();
        } catch (err) {
            setError(err?.response?.data?.error?.message || err.message || 'Selection failed');
        } finally {
            setBusy(false);
        }
    };

    const meta = env?.metadata || {};
    const selected = !!meta.is_enabled || !!meta.is_selected || meta.status === 'active';

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 className="h4 mb-1">{env?.name || 'Strategy artifact'}</h2>
                    <p className="text-muted small mb-0">
                        <code>{env?.slug || id}</code>
                        {env?.artifact_version != null && <> · v{env.artifact_version}</>}
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link to={basePath} className="btn btn-outline-secondary btn-sm">Back to registry</Link>
                    {selected && (
                        <Link to={`/strategy?strategy_id=${encodeURIComponent(id)}`} className="btn btn-outline-primary btn-sm">Open editor</Link>
                    )}
                    {!selected && (
                        <button type="button" className="btn btn-primary btn-sm" disabled={busy || !env} onClick={onActivate}>
                            Enable
                        </button>
                    )}
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={onExport} disabled={!env}>
                        Export JSON
                    </button>
                </div>
            </div>

            {error && <div className="alert alert-danger mb-0">{error}</div>}
            {loading && <div className="text-muted">Loading…</div>}

            {!loading && env && (
                <>
                    <div className="card">
                        <div className="card-body">
                            <dl className="row mb-0 small">
                                <dt className="col-sm-3">Intent</dt>
                                <dd className="col-sm-9">{meta.intent || '—'}</dd>
                                <dt className="col-sm-3">Summary</dt>
                                <dd className="col-sm-9">{meta.summary || meta.description || '—'}</dd>
                                <dt className="col-sm-3">Status / origin</dt>
                                <dd className="col-sm-9">{meta.status || '—'} · {meta.origin || '—'} · {selected ? 'selected' : 'not selected'}</dd>
                                <dt className="col-sm-3">Definition hash</dt>
                                <dd className="col-sm-9"><code className="small">{env.definition_hash || '—'}</code></dd>
                                <dt className="col-sm-3">Tags</dt>
                                <dd className="col-sm-9">
                                    {(meta.tags || []).length
                                        ? (meta.tags || []).map((t) => (
                                            <span key={t} className="badge text-bg-light me-1">{t}</span>
                                        ))
                                        : '—'}
                                </dd>
                                <dt className="col-sm-3">Dependencies</dt>
                                <dd className="col-sm-9">
                                    {(env.dependencies || []).length
                                        ? (env.dependencies || []).map((d) => `${d.artifact_type}:${d.ref}`).join(', ')
                                        : '—'}
                                </dd>
                            </dl>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-body">
                            <h3 className="h6">Version history</h3>
                            {versions.length === 0 && <p className="text-muted small mb-0">No versions recorded yet.</p>}
                            {versions.length > 0 && (
                                <div className="table-responsive">
                                    <table className="table table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>Version</th>
                                                <th>Status</th>
                                                <th>Hash</th>
                                                <th>Notes</th>
                                                <th>When</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {versions.map((v) => (
                                                <tr key={v.version}>
                                                    <td>v{v.version}{v.version_label ? ` (${v.version_label})` : ''}</td>
                                                    <td>{v.status}</td>
                                                    <td><code className="small">{v.definition_hash || '—'}</code></td>
                                                    <td>{v.change_notes || '—'}</td>
                                                    <td className="small text-muted">{v.created_at || '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-body">
                            <h3 className="h6">Definition (portable — Screener refs only)</h3>
                            <pre className="bg-light border rounded p-2 small mb-0 overflow-auto" style={{ maxHeight: 420 }}>
                                {JSON.stringify(env.definition, null, 2)}
                            </pre>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
