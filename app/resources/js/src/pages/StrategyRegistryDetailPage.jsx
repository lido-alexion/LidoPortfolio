import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import useRegistryArtifact from '../hooks/useRegistryArtifact';
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
    const { env, versions, loading, readError, versionsError, versionsLoading, load, loadVersions } = useRegistryArtifact('strategy', id);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const onExport = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const res = await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/export`);
            const data = res.data?.data;
            downloadJson(`${data?.slug || id}.json`, data);
            showToast('Exported portable JSON (no portfolio-local Screener ids).');
        } catch (err) {
            setError(getApiErrorMessage(err, 'Export failed'));
        } finally { setBusy(false); }
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
            setError(getApiErrorMessage(err, 'Selection failed'));
        } finally {
            setBusy(false);
        }
    };

    const onArchive = async () => {
        if (!window.confirm(
            'Archive this strategy? It will stop generating recommendations. Other enabled strategies stay enabled. Past holdings/recommendations keep their attribution.',
        )) return;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/archive`);
            showToast('Strategy archived.');
            await load();
        } catch (err) {
            setError(getApiErrorMessage(err, 'Archive failed'));
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
                    <Link to={`/strategy?strategy_id=${encodeURIComponent(id)}`} className="btn btn-outline-primary btn-sm">Open editor</Link>
                    {!selected && (
                        <button type="button" className="btn btn-primary btn-sm" disabled={busy || !env || Boolean(readError)} onClick={onActivate}>
                            Enable
                        </button>
                    )}
                    {selected && (
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            disabled={busy || !env || Boolean(readError)}
                            onClick={onArchive}
                        >
                            Archive
                        </button>
                    )}
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={onExport} disabled={busy || !env || Boolean(readError)}>
                        Export JSON
                    </button>
                </div>
            </div>

            {readError && <div className="alert alert-danger mb-0" role="alert">{readError} <button type="button" className="btn btn-sm btn-outline-primary" disabled={loading || busy} onClick={load}>Retry registry artifact</button></div>}
            {error && <div className="alert alert-danger mb-0" role="alert">{error}</div>}
            {versionsError && <div className="alert alert-warning mb-0" role="alert">{versionsError} <button type="button" className="btn btn-sm btn-outline-primary" disabled={versionsLoading || loading || busy} onClick={() => loadVersions()}>Retry registry versions</button></div>}
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
                                <dd className="col-sm-9">{meta.status || '—'} · {meta.origin || '—'} · {selected ? 'enabled' : 'not enabled'}</dd>
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
                            {!versionsError && versions.length === 0 && <p className="text-muted small mb-0">No versions recorded yet.</p>}
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
