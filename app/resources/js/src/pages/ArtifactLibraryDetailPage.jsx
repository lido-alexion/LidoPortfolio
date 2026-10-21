import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
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
    const [loadedArtifact, setLoadedArtifact] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [changes, setChanges] = useState(null);
    const [bundleRecovery, setBundleRecovery] = useState(null);
    const [artifactUuid, setArtifactUuid] = useState(uuid);
    const routeIdentity = useMemo(() => ({ uuid }), [uuid]);
    const comparisonSelection = useMemo(() => ({ routeIdentity, from, to }), [routeIdentity, from, to]);
    const committedRouteRef = useRef(null);
    const committedComparisonRef = useRef(null);
    const routeEpochRef = useRef(0);
    const loadGenerationRef = useRef(0);
    const comparisonGenerationRef = useRef(0);
    const activeComparisonRef = useRef(null);
    const comparisonBusyRef = useRef(false);
    const mountedRef = useRef(false);

    const invalidateComparison = useCallback(() => {
        const comparisonOwnedBusy = comparisonBusyRef.current && activeComparisonRef.current !== null;
        comparisonGenerationRef.current++;
        activeComparisonRef.current = null;
        comparisonBusyRef.current = false;
        setChanges(null);
        if (comparisonOwnedBusy) setBusy(false);
    }, []);

    useLayoutEffect(() => {
        if (committedRouteRef.current === routeIdentity) return;
        committedRouteRef.current = routeIdentity;
        routeEpochRef.current++;
        loadGenerationRef.current++;
        invalidateComparison();
        setLoadedArtifact(null);
        setArtifactUuid(uuid);
        setBundleRecovery(null);
        setError('');
        setLoading(true);
        setFrom('');
        setTo('');
    }, [routeIdentity, uuid, invalidateComparison]);

    useLayoutEffect(() => {
        if (committedComparisonRef.current === comparisonSelection) return;
        committedComparisonRef.current = comparisonSelection;
        invalidateComparison();
    }, [comparisonSelection, invalidateComparison]);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            routeEpochRef.current++;
            loadGenerationRef.current++;
            comparisonGenerationRef.current++;
            activeComparisonRef.current = null;
        };
    }, []);

    const load = useCallback(async () => {
        const requestedUuid = uuid;
        const requestedRoute = routeIdentity;
        if (!mountedRef.current || committedRouteRef.current !== requestedRoute) return;
        const epoch = routeEpochRef.current;
        const generation = ++loadGenerationRef.current;
        const isCurrent = () => mountedRef.current
            && committedRouteRef.current === requestedRoute
            && routeEpochRef.current === epoch
            && loadGenerationRef.current === generation;
        setLoading(true);
        setError('');
        try {
            const response = await api.get(`/v1/artifact-library/${requestedUuid}`);
            const data = response.data?.data || null;
            if (!isCurrent()) return;
            setLoadedArtifact(data);
            setArtifactUuid(requestedUuid);
            const published = (data?.versions || []).filter((version) => version.status === 'published');
            if (published.length >= 2) {
                setTo((current) => current || published[0].semver);
                setFrom((current) => current || published[1].semver);
            }
        } catch (requestError) {
            if (isCurrent()) setError(getApiErrorMessage(requestError, 'Failed to load artifact details.'));
        } finally {
            if (isCurrent()) setLoading(false);
        }
    }, [uuid, routeIdentity]);

    useEffect(() => {
        load();
        return () => { loadGenerationRef.current++; };
    }, [load]);
    const currentArtifact = artifactUuid === uuid ? loadedArtifact : null;
    const artifact = currentArtifact;
    const currentError = artifactUuid === uuid ? error : '';
    const routeLoading = artifactUuid !== uuid || loading;
    const published = useMemo(() => (currentArtifact?.versions || []).filter((version) => version.status === 'published'), [currentArtifact]);

    const act = async (operation, message) => {
        setBusy(true); setError('');
        try { await operation(); showToast(message); await load(); } catch (requestError) { setError(getApiErrorMessage(requestError)); } finally { setBusy(false); }
    };

    const compare = async () => {
        if (!from || !to || from === to) return;
        const requestedUuid = uuid;
        const requestedFrom = from;
        const requestedTo = to;
        const requestedRoute = routeIdentity;
        const requestedSelection = comparisonSelection;
        const epoch = routeEpochRef.current;
        const generation = ++comparisonGenerationRef.current;
        const comparison = { uuid: requestedUuid, from: requestedFrom, to: requestedTo, epoch, generation };
        activeComparisonRef.current = comparison;
        comparisonBusyRef.current = true;
        const isCurrent = () => mountedRef.current
            && committedRouteRef.current === requestedRoute
            && committedComparisonRef.current === requestedSelection
            && routeEpochRef.current === epoch
            && comparisonGenerationRef.current === generation
            && activeComparisonRef.current === comparison;
        setBusy(true); setError('');
        try {
            const response = await api.get(`/v1/artifact-library/${requestedUuid}/diff`, { params: { from: requestedFrom, to: requestedTo } });
            if (isCurrent()) setChanges({ uuid: requestedUuid, from: requestedFrom, to: requestedTo, rows: response.data?.data?.changes || [] });
        } catch (requestError) { if (isCurrent()) setError(getApiErrorMessage(requestError)); } finally {
            if (activeComparisonRef.current === comparison && comparisonBusyRef.current) {
                activeComparisonRef.current = null;
                comparisonBusyRef.current = false;
                setBusy(false);
            }
        }
    };

    const visibleChanges = changes?.uuid === uuid && changes?.from === from && changes?.to === to ? changes.rows : null;

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

    const readBundleDeployment = async (deploymentUuid) => {
        const response = await api.get(`/v1/artifact-library/bundle-deployments/${deploymentUuid}`);
        const evidence = response.data?.data;
        setBundleRecovery((current) => current?.deployment_uuid === deploymentUuid
            ? { ...current, evidence, readError: '' }
            : current);
        return evidence;
    };

    const deployBundle = async (version) => {
        setBusy(true); setError('');
        let deploymentUuid = bundleRecovery?.deployment_uuid;
        try {
            let evidence;
            if (deploymentUuid) {
                evidence = await readBundleDeployment(deploymentUuid);
                if (evidence.status !== 'planned') return;
            } else {
                const planned = await api.post(`/v1/artifact-library/versions/${version.id}/bundle-plan`);
                evidence = planned.data?.data;
                deploymentUuid = evidence?.deployment_uuid;
                if (!deploymentUuid) throw new Error('Bundle plan response did not include its deployment UUID.');
                setBundleRecovery({ deployment_uuid: deploymentUuid, evidence, readError: '' });
            }
            if (!window.confirm(`Deploy ${evidence?.items?.length || 0} Bundle members atomically to this Portfolio?`)) return;
            try {
                await api.post(`/v1/artifact-library/bundle-deployments/${deploymentUuid}/deploy`);
            } catch (requestError) {
                setError('Bundle apply response was uncertain. Inspecting the same deployment plan.');
                try { evidence = await readBundleDeployment(deploymentUuid); }
                catch (readError) {
                    setBundleRecovery((current) => current?.deployment_uuid === deploymentUuid
                        ? { ...current, readError: getApiErrorMessage(readError) }
                        : current);
                    return;
                }
                if (evidence.status === 'completed') {
                    setError('Bundle deployment completed; persisted member results confirm the application.');
                } else if (evidence.status === 'failed') {
                    setError('Bundle deployment failed; the atomic failure is recorded in this plan.');
                    return;
                } else {
                    setError('The same Bundle plan remains planned; review it before confirming any application.');
                    return;
                }
            }
            evidence = await readBundleDeployment(deploymentUuid);
            if (evidence.status === 'completed') {
                showToast('Bundle deployed atomically.');
                await load();
            }
        } catch (requestError) { setError(getApiErrorMessage(requestError)); }
        finally { setBusy(false); }
    };

    const inspectBundleDeployment = async () => {
        if (!bundleRecovery?.deployment_uuid) return;
        setBusy(true);
        try { await readBundleDeployment(bundleRecovery.deployment_uuid); setError(''); }
        catch (requestError) { setBundleRecovery((current) => current ? { ...current, readError: getApiErrorMessage(requestError) } : current); }
        finally { setBusy(false); }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between gap-2">
                <div><h2 className="h4 mb-1">{artifact?.name || 'Artifact'}</h2><p className="small text-muted mb-0"><code>{artifact?.slug || uuid}</code>{artifact && ` · ${artifact.type} · ${artifact.permission} · ${artifact.origin}`}</p></div>
                <Link className="btn btn-sm btn-outline-secondary align-self-start" to="/artifact-library">Back to Library</Link>
            </div>
            {currentError && <div className="alert alert-danger mb-0" role="alert">{currentError}{!artifact && <button type="button" className="btn btn-sm btn-outline-secondary ms-2" disabled={routeLoading || busy} onClick={load}>Retry artifact</button>}</div>}
            {routeLoading && <div className="text-muted">Loading artifact…</div>}
            {!routeLoading && artifact && <>
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
                    {visibleChanges && <div className="table-responsive mt-3"><table className="table table-sm"><thead><tr><th>Path</th><th>Change</th><th>Before</th><th>After</th></tr></thead><tbody>{visibleChanges.map((change, index) => <tr key={`${change.path}-${index}`}><td><code>{change.path}</code></td><td>{change.change}</td><td><code>{JSON.stringify(change.before)}</code></td><td><code>{JSON.stringify(change.after)}</code></td></tr>)}</tbody></table>{visibleChanges.length === 0 && <p className="text-muted small mb-0">No structural changes.</p>}</div>}
                </div></div>}

                <div className="d-grid gap-3">{artifact.versions.map((version) => <div className="card" key={version.id}><div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between gap-2"><div><h3 className="h6 mb-1">{version.semver} <span className={`badge ${version.status === 'published' ? 'text-bg-success' : 'text-bg-warning'}`}>{version.status}</span></h3><p className="small text-muted mb-2">{version.change_summary || 'No change summary'} · <code>{version.definition_hash}</code></p></div><div className="d-flex flex-wrap gap-2">{version.status === 'published' && <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy} onClick={() => exportVersion(version)}>Export</button>}{version.status === 'published' && artifact.permission === 'owner' && !artifact.draft_version && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => nextDraft(version)}>New version Draft</button>}{version.status === 'published' && artifact.permission === 'owner' && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => share(version)}>Share</button>}{version.status === 'published' && artifact.permission !== 'owner' && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => fork(version)}>Fork</button>}{version.status === 'published' && artifact.type !== 'bundle' && <button type="button" className="btn btn-sm btn-primary" disabled={busy || artifact.portfolio_binding?.active_version === version.semver} onClick={() => bindOrUpgrade(version)}>{artifact.portfolio_binding ? 'Upgrade binding' : 'Bind to Portfolio'}</button>}{version.status === 'published' && artifact.type === 'bundle' && <button type="button" className="btn btn-sm btn-primary" disabled={busy || (bundleRecovery && bundleRecovery.evidence?.status !== 'planned')} onClick={() => deployBundle(version)}>{bundleRecovery ? 'Existing Bundle plan needs review' : 'Deploy Bundle'}</button>}</div></div>
                    {version.status === 'draft' && artifact.permission === 'owner' ? <DraftEditor artifact={artifact} version={version} busy={busy} onSaved={act} /> : <>
                    <h4 className="small fw-semibold mt-2">Exact dependencies</h4>{version.dependencies.length ? <ul className="small">{version.dependencies.map((dependency, index) => <li key={index}>{dependency.kind}: <code>{dependency.artifact_uuid ? `${dependency.artifact_uuid}@${dependency.artifact_version}` : `${dependency.indicator_id}@${dependency.indicator_version}`}</code></li>)}</ul> : <p className="small text-muted">None</p>}
                    <details><summary className="small">Definition and documentation</summary><pre className="bg-light border rounded p-2 small mt-2 overflow-auto">{JSON.stringify({ content: version.content, documentation: version.documentation }, null, 2)}</pre></details>
                    </>}
                </div></div>)}</div>
                {bundleRecovery && <section className="alert alert-warning mb-0" aria-label="Bundle deployment recovery"><div className="fw-semibold">Existing Bundle deployment</div><div>Plan <code>{bundleRecovery.deployment_uuid}</code> · {bundleRecovery.evidence?.status || 'status not confirmed'}</div><button type="button" className="btn btn-sm btn-outline-secondary mt-2" disabled={busy} onClick={inspectBundleDeployment}>Inspect this plan</button>{bundleRecovery.readError && <div role="alert" className="small mt-2">{bundleRecovery.readError}</div>}{bundleRecovery.evidence?.status === 'completed' && <ul className="small mt-2 mb-0">{bundleRecovery.evidence.items.map((item) => <li key={item.artifact_uuid}>{item.artifact_uuid} {item.version} · {item.action} · revision {item.resulting_revision_id ?? 'not recorded'}</li>)}</ul>}{bundleRecovery.evidence?.status === 'failed' && <div className="small mt-2">Failure evidence: {bundleRecovery.evidence.error_code || 'no error code recorded'}. No further action is available for this plan.</div>}{bundleRecovery.evidence?.status === 'planned' && <div className="small mt-2">This plan remains unapplied. Choose Deploy Bundle to inspect and confirm this same plan before applying it.</div>}</section>}
                {artifact.permission === 'owner' && !artifact.archived_at && <div><button type="button" className="btn btn-sm btn-outline-danger" disabled={busy} onClick={() => window.confirm('Archive this artifact? Published history and active bindings remain intact.') && act(() => api.post(`/v1/artifact-library/${uuid}/archive`), 'Artifact archived; immutable history was preserved.')}>Archive artifact</button></div>}
            </>}
        </div>
    );
}
