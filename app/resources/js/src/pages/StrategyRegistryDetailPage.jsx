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
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [archiveBlockers, setArchiveBlockers] = useState([]);
    const { env, versions, loading, readError, versionsError, versionsLoading, load, loadVersions, scopeRef } = useRegistryArtifact('strategy', id, { onScopeChange: () => { setError(''); setBusy(false); } });

    const onExport = async () => {
        if (busy) return;
        const scope = scopeRef.current;
        setBusy(true);
        setError('');
        try {
            const res = await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/export`);
            if (scope !== scopeRef.current) return;
            const data = res.data?.data;
            downloadJson(`${data?.slug || id}.json`, data);
            showToast('Exported portable JSON (no portfolio-local Screener ids).');
        } catch (err) {
            if (scope !== scopeRef.current) return;
            setError(getApiErrorMessage(err, 'Export failed'));
        } finally { if (scope === scopeRef.current) setBusy(false); }
    };

    const onActivate = async () => {
        if (!window.confirm(`Enable “${env?.name || id}” for this portfolio? Other enabled strategies stay enabled.`)) return;
        const scope = scopeRef.current;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/activate`);
            if (scope !== scopeRef.current) return;
            showToast('Strategy enabled for this portfolio.');
            await load();
        } catch (err) {
            if (scope !== scopeRef.current) return;
            setError(getApiErrorMessage(err, 'Selection failed'));
        } finally {
            if (scope === scopeRef.current) setBusy(false);
        }
    };

    const onDisable = async () => {
        if (!window.confirm('Pause this strategy? New recommendations and future runs stop. Existing holdings, orders, executions and settlement remain available. No sell, cancellation or fund release is automatic.')) return;
        const scope = scopeRef.current;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/disable`);
            if (scope !== scopeRef.current) return;
            showToast('Strategy paused. Existing work remains available.');
            await load();
        } catch (err) {
            if (scope === scopeRef.current) setError(getApiErrorMessage(err, 'Disable failed'));
        } finally { if (scope === scopeRef.current) setBusy(false); }
    };

    const onRestore = async () => {
        const scope = scopeRef.current;
        setBusy(true);
        setError('');
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/restore`);
            if (scope !== scopeRef.current) return;
            showToast('Strategy restored as disabled. Enable it explicitly after reviewing readiness.');
            await load();
        } catch (err) {
            if (scope === scopeRef.current) setError(getApiErrorMessage(err, 'Restore failed'));
        } finally { if (scope === scopeRef.current) setBusy(false); }
    };

    const onArchive = async () => {
        const reason = window.prompt(`Why are you retiring “${env?.name || id}”? The reason is retained with read-only history.`)?.trim();
        if (!reason || reason.length < 3) return;
        const scope = scopeRef.current;
        setBusy(true);
        setError('');
        setArchiveBlockers([]);
        try {
            await api.post(`/v1/strategy-registry/${encodeURIComponent(id)}/archive`, { reason });
            if (scope !== scopeRef.current) return;
            showToast('Strategy archived.');
            await load();
        } catch (err) {
            if (scope !== scopeRef.current) return;
            setError(getApiErrorMessage(err, 'Archive failed'));
            setArchiveBlockers(err?.response?.data?.meta?.blockers || []);
        } finally {
            if (scope === scopeRef.current) setBusy(false);
        }
    };

    const meta = env?.metadata || {};
    const selected = !!meta.is_enabled || !!meta.is_selected || meta.status === 'active';
    const lifecycleStatus = meta.lifecycle_status || meta.status;

    return (
        <div className="contentPane d-grid gap-3">
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
                    {lifecycleStatus === 'archived' ? (
                        <button type="button" className="btn btn-outline-primary btn-sm" disabled={busy || !env || Boolean(readError)} onClick={onRestore}>Restore as disabled</button>
                    ) : !selected && (
                        <button type="button" className="btn btn-primary btn-sm" disabled={busy || !env || Boolean(readError)} onClick={onActivate}>
                            Enable
                        </button>
                    )}
                    {selected && <button type="button" className="btn btn-outline-warning btn-sm" disabled={busy || !env || Boolean(readError)} onClick={onDisable}>Disable</button>}
                    {lifecycleStatus !== 'archived' && (
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            disabled={busy || !env || Boolean(readError)}
                            onClick={onArchive}
                        >
                            Retire
                        </button>
                    )}
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={onExport} disabled={busy || !env || Boolean(readError)}>
                        Export JSON
                    </button>
                </div>
            </div>

            {readError && <div className="alert alert-danger mb-0" role="alert">{readError} <button type="button" className="btn btn-sm btn-outline-primary" disabled={loading || busy} onClick={load}>Retry registry artifact</button></div>}
            {error && <div className="alert alert-danger mb-0" role="alert">{error}</div>}
            {archiveBlockers.length > 0 && <div className="alert alert-warning mb-0" role="alert"><strong>Resolve these records before retiring:</strong><ul className="mb-0 mt-2">{archiveBlockers.map((blocker) => <li key={blocker.code}>{blocker.label} ({blocker.count}) — <Link to={blocker.href}>Open resolution view</Link></li>)}</ul></div>}
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
                                <dd className="col-sm-9">{lifecycleStatus || '—'} · {meta.origin || '—'} · {selected ? 'enabled' : 'not enabled'}</dd>
                                {lifecycleStatus === 'archived' ? <><dt className="col-sm-3">Retirement reason</dt><dd className="col-sm-9">{meta.archive_reason || '—'}{meta.archived_at ? ` · ${meta.archived_at}` : ''}</dd></> : null}
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
                                <div className="table-responsive" tabIndex={0} role="region" aria-label="Strategy version history">
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
                            <pre tabIndex={0} role="region" aria-label="Strategy definition JSON" className="border rounded p-2 small mb-0 overflow-auto" style={{ maxHeight: 420, backgroundColor: 'var(--lido-bg-surface)', color: 'var(--lido-text)' }}>
                                {JSON.stringify(env.definition, null, 2)}
                            </pre>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
