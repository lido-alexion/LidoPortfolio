import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

const value = (v) => v == null || v === '' ? '—' : String(v);

export default function ForwardDataAdminPage() {
    const [payload, setPayload] = useState(null);
    const [work, setWork] = useState([]);
    const [busy, setBusy] = useState(false);
    const [loadError, setLoadError] = useState(false);

    const load = useCallback(async () => {
        const [health, queue] = await Promise.all([
            api.get('/forward-data/health'),
            api.get('/forward-data/work', { params: { per_page: 50 } }),
        ]);
        setPayload(health.data?.data || null);
        setWork(queue.data?.data?.data || queue.data?.data || []);
        setLoadError(false);
    }, []);

    useEffect(() => { load().catch(() => { setLoadError(true); showToast('Failed to load forward-data health', 'danger'); }); }, [load]);

    const control = async (action) => {
        setBusy(true);
        try {
            await api.post(`/forward-data/${action}`, action === 'retry' ? { ids: work.filter((row) => row.state !== 'succeeded').map((row) => row.id).slice(0, 100) } : {});
            await load();
            showToast(`Forward-data ${action} complete`);
        } catch (error) {
            showToast(error?.response?.data?.message || `Forward-data ${action} failed`, 'danger');
        } finally { setBusy(false); }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div><h1 className="h5 mb-1">Forward data collection</h1><p className="text-muted small mb-0">Freshness and validated coverage for DATA-003.</p></div>
                <Link to="/settings/admin-alerts" className="btn btn-sm btn-outline-secondary">Admin alerts</Link>
            </div>
            <div className="d-flex gap-2 mb-3 flex-wrap">
                <button aria-label="Run due forward-data checks" className="btn btn-primary btn-sm" disabled={busy} onClick={() => control('dispatch')}>Run due checks</button>
                <button aria-label="Retry visible forward-data failures" className="btn btn-outline-warning btn-sm" disabled={busy || work.every((row) => row.state === 'succeeded')} onClick={() => control('retry')}>Retry visible failures</button>
                <button aria-label={payload?.paused ? 'Resume forward-data dispatch' : 'Pause forward-data dispatch'} className="btn btn-outline-secondary btn-sm" disabled={busy} onClick={() => control(payload?.paused ? 'resume' : 'pause')}>{payload?.paused ? 'Resume dispatch' : 'Pause dispatch'}</button>
                <button aria-label="Refresh forward-data health" className="btn btn-link btn-sm" disabled={busy} onClick={() => load().catch(() => { setLoadError(true); showToast('Failed to refresh forward-data health', 'danger'); })}>Refresh</button>
            </div>
            {loadError && <div className="alert alert-danger" role="alert">Forward-data health could not be refreshed. Retry after checking the Admin session and scheduler health.</div>}
            <div className="row g-3 mb-3">
                {Object.entries(payload?.health?.datasets || {}).map(([key, dataset]) => (
                    <div className="col-12 col-md-6 col-xl-4" key={key}><div className="card h-100"><div className="card-body"><div className="text-muted small">{key}</div><div className="h5">{value(dataset.state || dataset.freshness)}</div><div className="small">Coverage: {value(dataset.coverage)} · Backlog: {value(dataset.unresolved ?? dataset.backlog)}</div><div className="small text-muted">Last success: {value(dataset.last_successful_check || dataset.last_successful_ingestion)}</div></div></div></div>
                ))}
            </div>
            <div className="card"><div className="card-header">Durable work</div><div className="table-responsive"><table className="table table-sm mb-0"><thead><tr><th>Dataset</th><th>Session</th><th>State</th><th>Attempts</th><th>Reason</th></tr></thead><tbody>{work.map((row) => <tr key={row.id}><td>{row.dataset_key}</td><td>{value(row.session_date)}</td><td>{row.state}</td><td>{row.attempts}</td><td>{value(row.last_error_code)}</td></tr>)}{work.length === 0 && <tr><td colSpan="5" className="text-muted">No forward work recorded.</td></tr>}</tbody></table></div></div>
            <p className="small text-muted mt-3 mb-0">Freshness means the last successful check; coverage means validated persisted data. Neither starts training, promotion, lifecycle, or drift automation.</p>
        </div>
    );
}
