import React, { useState } from 'react';
import api from '../api';

const TOKEN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export function exportDownloadUrl(artifact) {
    if (artifact?.status !== 'ready' || !TOKEN.test(artifact.token || '') || !artifact.expires_at || Date.parse(artifact.expires_at) <= Date.now()) return null;
    if (!Number.isFinite(Date.parse(artifact.expires_at))) return null;
    try {
        const url = new URL(artifact.download_url, window.location.origin);
        return url.origin === window.location.origin && !url.username && !url.password && url.pathname === `/api/exports/${artifact.token}/download` && !url.search && !url.hash ? url.href : null;
    } catch { return null; }
}

/** Current export only: account-scoped status/cancellation, never export history. */
export default function ExportArtifactStatus({ artifact }) {
    const [job, setJob] = useState(artifact);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const validToken = TOKEN.test(job?.token || '');
    const pending = ['queued', 'running'].includes(job?.status);
    const download = exportDownloadUrl(job);
    const refresh = async (cancel = false) => {
        if (!validToken || busy) return;
        setBusy(true);
        setError('');
        try {
            if (cancel) {
                const response = await api.post(`/exports/${job.token}/cancel`);
                setJob(current => ({ ...current, status: response.data?.data?.status || current.status, download_url: null }));
            }
            const response = await api.get(`/exports/${job.token}`);
            const next = response.data?.data;
            if (!next || next.token !== job.token) throw new Error('Invalid export status');
            setJob(next);
        } catch {
            setError(cancel ? 'Export cancellation or status refresh could not be confirmed. Refresh the status before trying again.' : 'Export status could not be loaded. Please retry.');
        } finally { setBusy(false); }
    };
    const status = job?.status;
    const message = status === 'ready'
        ? (download ? 'Export ready. Download it before its 24-hour retention expires.' : 'This export is no longer downloadable. Generate it again.')
        : status === 'cancelled' ? 'Export cancelled. No download is available.'
            : status === 'failed' ? (job.failure?.message || 'Export generation failed. Refresh the data and generate it again.')
                : status === 'running' ? 'Export is being prepared.'
                    : status === 'queued' ? 'Export is queued.' : 'Export status is unavailable.';
    return <section className="border rounded p-2 mt-2" aria-label="Latest export">
        <p className="small mb-2" role="status">{message}</p>
        {!validToken && <p role="alert" className="small text-danger">Export status is unavailable. Generate the export again.</p>}
        {error && <p role="alert" className="small text-danger">{error}</p>}
        <div className="d-flex flex-wrap gap-2">
            <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy || !validToken} onClick={() => refresh()}>Refresh export status</button>
            {pending && <button type="button" className="btn btn-sm btn-outline-danger" disabled={busy || !validToken} onClick={() => refresh(true)}>Cancel export</button>}
            {download && <a className="btn btn-sm btn-primary" href={download}>Download export</a>}
        </div>
    </section>;
}
