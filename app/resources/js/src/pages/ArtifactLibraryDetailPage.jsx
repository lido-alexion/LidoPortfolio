import React, { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

const stateBadge = (state) => ({ usable: 'text-bg-success', warning: 'text-bg-warning', blocked: 'text-bg-danger' }[state] || 'text-bg-light');

function download(filename, value) {
    const url = URL.createObjectURL(new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
}

function DraftEditor({ artifact, version, busy, onSaved }) {
    const [content, setContent] = useState(JSON.stringify(version.content, null, 2));
    const [documentation, setDocumentation] = useState(JSON.stringify(version.documentation || {}, null, 2));
    const [summary, setSummary] = useState(version.change_summary || '');

    const save = () => onSaved(() => api.put(`/v1/artifact-library/versions/${version.id}`, {
        expected_lock_version: version.lock_version,
        content: JSON.parse(content),
        documentation: JSON.parse(documentation),
        change_summary: summary || null,
    }), 'Draft saved as a new optimistic revision.');

    const publish = () => {
        const raw = window.prompt('Exact dependencies JSON array. Use [] when none.', '[]');
        if (raw == null) return;
        return onSaved(() => api.post(`/v1/artifact-library/versions/${version.id}/publish`, {
            dependencies: JSON.parse(raw), change_summary: summary || null,
        }), 'Artifact version published immutably.');
    };

    return <div className="d-grid gap-2">
        <div className="alert alert-warning py-2 small mb-0">Draft only. Saving never publishes or deploys this artifact.</div>
        <label className="form-label small mb-0">Change summary<input className="form-control form-control-sm" value={summary} onChange={(event) => setSummary(event.target.value)} /></label>
        <label className="form-label small mb-0">Artifact envelope JSON<textarea className="form-control font-monospace" rows="12" value={content} onChange={(event) => setContent(event.target.value)} /></label>
        <label className="form-label small mb-0">Versioned documentation JSON<textarea className="form-control font-monospace" rows="5" value={documentation} onChange={(event) => setDocumentation(event.target.value)} /></label>
        <div className="d-flex gap-2"><button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={save}>Save Draft</button><button type="button" className="btn btn-sm btn-success" disabled={busy || artifact.permission !== 'owner'} onClick={publish}>Validate and publish</button></div>
    </div>;
}

export default function ArtifactLibraryDetailPage() {
    const { uuid } = useParams();
    const [artifact, setArtifact] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [changes, setChanges] = useState(null);

    const load = async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get(`/v1/artifact-library/${uuid}`);
            const data = response.data?.data || null;
            setArtifact(data);
            const published = (data?.versions || []).filter((version) => version.status === 'published');
            if (published.length >= 2) {
                setTo((current) => current || published[0].semver);
                setFrom((current) => current || published[1].semver);
            }
        } catch (requestError) {
            setError(getApiErrorMessage(requestError, 'Failed to load artifact details.'));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, [uuid]); // eslint-disable-line react-hooks/exhaustive-deps
    const published = useMemo(() => (artifact?.versions || []).filter((version) => version.status === 'published'), [artifact]);

    const act = async (operation, message) => {
        setBusy(true); setError('');
        try { await operation(); showToast(message); await load(); } catch (requestError) { setError(getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    const compare = async () => {
        if (!from || !to || from === to) return;
        setBusy(true); setError('');
        try {
            const response = await api.get(`/v1/artifact-library/${uuid}/diff`, { params: { from, to } });
            setChanges(response.data?.data?.changes || []);
        } catch (requestError) { setError(getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    const exportVersion = async (version) => {
        setBusy(true); setError('');
        try {
            const response = await api.post(`/v1/artifact-library/versions/${version.id}/export`);
            download(`${artifact.slug}-${version.semver}.json`, response.data?.data);
            showToast('Published package downloaded.');
        } catch (requestError) { setError(getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    const bindOrUpgrade = (version) => {
        const binding = artifact.portfolio_binding;
        const suggestedSettings = version.content?.metadata?.suggested_binding_settings || {};
        if (!binding) return act(() => api.post(`/v1/artifact-library/versions/${version.id}/bind`, { enabled: false, settings: suggestedSettings }), 'Artifact bound to this Portfolio.');
        if (binding.active_version === version.semver) return;
        return act(() => api.put(`/v1/artifact-bindings/${binding.binding_uuid}/upgrade`, {
            artifact_version_id: version.id,
            expected_lock_version: binding.lock_version,
        }), `Binding upgraded to ${version.semver}.`);
    };

    const share = (version) => {
        const email = window.prompt('Recipient account email');
        if (!email) return;
        return act(() => api.post(`/v1/artifact-library/versions/${version.id}/share`, { recipient_email: email }), 'Immutable version and dependency access shared.');
    };

    const fork = (version) => {
        const slug = window.prompt('New artifact slug');
        if (!slug) return;
        const name = window.prompt('New artifact name', `${artifact.name} Fork`);
        if (!name) return;
        return act(() => api.post(`/v1/artifact-library/versions/${version.id}/fork`, { slug, name }), 'Fork created as an unpublished 1.0.0 Draft.');
    };

    const nextDraft = (version) => {
        const semver = window.prompt('New forward version (SemVer)');
        if (!semver) return;
        return act(() => api.post(`/v1/artifact-library/versions/${version.id}/next-draft`, { semver }), `Created Draft ${semver}.`);
    };

    const toggleBinding = () => {
        const binding = artifact.portfolio_binding;
        return act(() => api.put(`/v1/artifact-bindings/${binding.binding_uuid}/enabled`, {
            expected_lock_version: binding.lock_version,
            enabled: binding.status !== 'enabled',
        }), binding.status === 'enabled' ? 'Binding disabled.' : 'Binding enabled.');
    };

    const deployBundle = async (version) => {
        setBusy(true); setError('');
        try {
            const planned = await api.post(`/v1/artifact-library/versions/${version.id}/bundle-plan`);
            const plan = planned.data?.data;
            if (!window.confirm(`Deploy ${plan?.items?.length || 0} Bundle members atomically to this Portfolio?`)) return;
            await api.post(`/v1/artifact-library/bundle-deployments/${plan.deployment_uuid}/deploy`);
            showToast('Bundle deployed atomically.');
            await load();
        } catch (requestError) { setError(getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between gap-2">
                <div><h2 className="h4 mb-1">{artifact?.name || 'Artifact'}</h2><p className="small text-muted mb-0"><code>{artifact?.slug || uuid}</code>{artifact && ` · ${artifact.type} · ${artifact.permission} · ${artifact.origin}`}</p></div>
                <Link className="btn btn-sm btn-outline-secondary align-self-start" to="/artifact-library">Back to Library</Link>
            </div>
            {error && <div className="alert alert-danger mb-0">{error}</div>}
            {loading && <div className="text-muted">Loading artifact…</div>}
            {!loading && artifact && <>
                <div className="card"><div className="card-body">
                    <h3 className="h6">Portfolio deployment</h3>
                    {artifact.portfolio_binding ? <div className="d-flex flex-wrap gap-2 align-items-center"><span className={`badge ${stateBadge(artifact.portfolio_binding.usability_state)}`}>{artifact.portfolio_binding.usability_state}</span><span>{artifact.portfolio_binding.status} on {artifact.portfolio_binding.active_version}</span>{(artifact.portfolio_binding.usability_reasons || []).map((reason) => <code className="small" key={reason}>{reason}</code>)}<button type="button" className="btn btn-sm btn-outline-secondary" onClick={toggleBinding} disabled={busy || artifact.portfolio_binding.usability_state === 'blocked' && artifact.portfolio_binding.status !== 'enabled'}>{artifact.portfolio_binding.status === 'enabled' ? 'Disable' : 'Enable'}</button></div> : <span className="text-muted">This artifact is not bound to the active Portfolio.</span>}
                </div></div>

                {published.length >= 2 && <div className="card"><div className="card-body">
                    <h3 className="h6">Structural version comparison</h3>
                    <div className="d-flex flex-wrap gap-2 align-items-end">
                        <select className="form-select form-select-sm w-auto" aria-label="Compare from version" value={from} onChange={(event) => setFrom(event.target.value)}>{published.map((version) => <option key={version.id}>{version.semver}</option>)}</select>
                        <span>to</span>
                        <select className="form-select form-select-sm w-auto" aria-label="Compare to version" value={to} onChange={(event) => setTo(event.target.value)}>{published.map((version) => <option key={version.id}>{version.semver}</option>)}</select>
                        <button className="btn btn-sm btn-outline-primary" type="button" onClick={compare} disabled={busy || from === to}>Compare</button>
                    </div>
                    {changes && <div className="table-responsive mt-3"><table className="table table-sm"><thead><tr><th>Path</th><th>Change</th><th>Before</th><th>After</th></tr></thead><tbody>{changes.map((change, index) => <tr key={`${change.path}-${index}`}><td><code>{change.path}</code></td><td>{change.change}</td><td><code>{JSON.stringify(change.before)}</code></td><td><code>{JSON.stringify(change.after)}</code></td></tr>)}</tbody></table>{changes.length === 0 && <p className="text-muted small mb-0">No structural changes.</p>}</div>}
                </div></div>}

                <div className="d-grid gap-3">{artifact.versions.map((version) => <div className="card" key={version.id}><div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between gap-2"><div><h3 className="h6 mb-1">{version.semver} <span className={`badge ${version.status === 'published' ? 'text-bg-success' : 'text-bg-warning'}`}>{version.status}</span></h3><p className="small text-muted mb-2">{version.change_summary || 'No change summary'} · <code>{version.definition_hash}</code></p></div><div className="d-flex flex-wrap gap-2">{version.status === 'published' && <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy} onClick={() => exportVersion(version)}>Export</button>}{version.status === 'published' && artifact.permission === 'owner' && !artifact.draft_version && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => nextDraft(version)}>New version Draft</button>}{version.status === 'published' && artifact.permission === 'owner' && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => share(version)}>Share</button>}{version.status === 'published' && artifact.permission !== 'owner' && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => fork(version)}>Fork</button>}{version.status === 'published' && artifact.type !== 'bundle' && <button type="button" className="btn btn-sm btn-primary" disabled={busy || artifact.portfolio_binding?.active_version === version.semver} onClick={() => bindOrUpgrade(version)}>{artifact.portfolio_binding ? 'Upgrade binding' : 'Bind to Portfolio'}</button>}{version.status === 'published' && artifact.type === 'bundle' && <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => deployBundle(version)}>Deploy Bundle</button>}</div></div>
                    {version.status === 'draft' && artifact.permission === 'owner' ? <DraftEditor artifact={artifact} version={version} busy={busy} onSaved={act} /> : <>
                    <h4 className="small fw-semibold mt-2">Exact dependencies</h4>{version.dependencies.length ? <ul className="small">{version.dependencies.map((dependency, index) => <li key={index}>{dependency.kind}: <code>{dependency.artifact_uuid ? `${dependency.artifact_uuid}@${dependency.artifact_version}` : `${dependency.indicator_id}@${dependency.indicator_version}`}</code></li>)}</ul> : <p className="small text-muted">None</p>}
                    <details><summary className="small">Definition and documentation</summary><pre className="bg-light border rounded p-2 small mt-2 overflow-auto">{JSON.stringify({ content: version.content, documentation: version.documentation }, null, 2)}</pre></details>
                    </>}
                </div></div>)}</div>
                {artifact.permission === 'owner' && !artifact.archived_at && <div><button type="button" className="btn btn-sm btn-outline-danger" disabled={busy} onClick={() => window.confirm('Archive this artifact? Published history and active bindings remain intact.') && act(() => api.post(`/v1/artifact-library/${uuid}/archive`), 'Artifact archived; immutable history was preserved.')}>Archive artifact</button></div>}
            </>}
        </div>
    );
}
