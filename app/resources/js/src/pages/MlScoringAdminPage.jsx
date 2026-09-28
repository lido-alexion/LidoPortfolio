import React, { useCallback, useEffect, useRef, useState } from 'react';
import api from '../api';
import { appUrl } from '../appBase';
import { showToast } from '../toast';

const ACTIVE_RUN_STATUSES = ['queued', 'running', 'cancelling'];

async function waitForActiveRun(horizon, attempts = 40) {
    for (let i = 0; i < attempts; i += 1) {
        const { data } = await api.get('/v1/admin/ml/runs', { params: { horizon, limit: 5 } });
        const active = (data.data?.runs || []).find((row) => ACTIVE_RUN_STATUSES.includes(row.status));
        if (active) {
            return active.id;
        }
        await new Promise((resolve) => { setTimeout(resolve, 500); });
    }
    return null;
}

function streamRunProgress(runId, onProgress) {
    return new Promise((resolve) => {
        const url = appUrl(`/api/v1/admin/ml/runs/${runId}/stream`);
        const source = new EventSource(url);

        const finish = (payload) => {
            source.close();
            resolve(payload);
        };

        source.addEventListener('progress', (event) => {
            try {
                const payload = JSON.parse(event.data);
                onProgress(payload);
                if (!ACTIVE_RUN_STATUSES.includes(payload.status)) {
                    finish(payload);
                }
            } catch {
                finish(null);
            }
        });

        source.onerror = () => finish(null);
    });
}

export default function MlScoringAdminPage() {
    const [dashboard, setDashboard] = useState(null);
    const [horizon, setHorizon] = useState('3m');
    const [busy, setBusy] = useState(false);
    const [promotionReview, setPromotionReview] = useState(null);
    const [reviewModelId, setReviewModelId] = useState(null);
    const [runs, setRuns] = useState([]);
    const [runDetail, setRunDetail] = useState(null);
    const [liveProgress, setLiveProgress] = useState(null);
    const [retentionPlan, setRetentionPlan] = useState(null);
    const streamAbortRef = useRef(false);

    const loadRetentionPlan = useCallback(async () => {
        const { data } = await api.get('/v1/admin/ml/retention-plan');
        setRetentionPlan(data.data);
    }, []);

    const load = useCallback(async () => {
        const [{ data }, runsRes] = await Promise.all([
            api.get('/v1/admin/ml'),
            api.get('/v1/admin/ml/runs', { params: { horizon, limit: 15 } }),
        ]);
        setDashboard(data.data);
        setRuns(runsRes.data?.data?.runs || []);
        await loadRetentionPlan();
    }, [horizon, loadRetentionPlan]);

    useEffect(() => { load(); }, [load]);

    useEffect(() => () => {
        streamAbortRef.current = true;
    }, []);

    const retrain = async () => {
        setBusy(true);
        setLiveProgress(null);
        streamAbortRef.current = false;
        try {
            await api.post('/v1/admin/ml/retrain-queue', { horizon });
            showToast(`Queued ${horizon} retrain. Streaming progress…`, 'info');
            const runId = await waitForActiveRun(horizon);
            if (runId && !streamAbortRef.current) {
                await streamRunProgress(runId, (payload) => {
                    setLiveProgress(payload.progress || null);
                    setRuns((prev) => prev.map((row) => (
                        row.id === runId
                            ? { ...row, status: payload.status, progress: payload.progress }
                            : row
                    )));
                });
            }
            await load();
            showToast(`Training run finished for ${horizon}.`, 'success');
        } catch (err) {
            showToast(err?.response?.data?.error?.message || err.message || 'Retrain failed', 'danger');
        } finally {
            setBusy(false);
            setLiveProgress(null);
        }
    };

    const loadRunDetail = async (runId) => {
        setBusy(true);
        try {
            const { data } = await api.get(`/v1/admin/ml/runs/${runId}`);
            setRunDetail(data.data);
        } catch (err) {
            showToast(err?.response?.data?.error?.message || err.message || 'Could not load run detail', 'danger');
        } finally {
            setBusy(false);
        }
    };

    const cancelRun = async (runId) => {
        setBusy(true);
        try {
            await api.post(`/v1/admin/ml/runs/${runId}/cancel`);
            showToast('Cancellation requested.', 'info');
            await load();
        } catch (err) {
            showToast(err?.response?.data?.error?.message || err.message || 'Cancel failed', 'danger');
        } finally {
            setBusy(false);
        }
    };

    const loadPromotionReview = async (modelId) => {
        setBusy(true);
        try {
            const { data } = await api.get(`/v1/admin/ml/models/${modelId}/promotion-review`);
            setPromotionReview(data.data);
            setReviewModelId(modelId);
        } finally {
            setBusy(false);
        }
    };

    const promote = async (modelId) => {
        setBusy(true);
        try {
            await api.post(`/v1/admin/ml/models/${modelId}/promote`);
            showToast('Model promoted.', 'success');
            setPromotionReview(null);
            setReviewModelId(null);
            await load();
        } finally {
            setBusy(false);
        }
    };

    const applyRetention = async () => {
        if (!window.confirm('Prune retained ML artifacts beyond the configured cap? Active models are never deleted.')) {
            return;
        }
        setBusy(true);
        try {
            const { data } = await api.get('/v1/admin/ml/retention-plan', { params: { apply: 1 } });
            const count = (data.data?.applied || []).length;
            showToast(count ? `Pruned ${count} artifact(s).` : 'Nothing to prune.', 'success');
            await load();
        } catch (err) {
            showToast(err?.response?.data?.error?.message || err.message || 'Retention prune failed', 'danger');
        } finally {
            setBusy(false);
        }
    };

    const checkDrift = async (modelId) => {
        setBusy(true);
        try {
            await api.post(`/v1/admin/ml/models/${modelId}/drift-check`, { window_months: 3 });
            showToast('Drift check recorded.', 'success');
            await load();
        } finally {
            setBusy(false);
        }
    };

    const updateSchedule = async (row, enabled, schedule) => {
        setBusy(true);
        try {
            await api.put(`/v1/admin/ml/schedules/${row.horizon}`, { enabled, schedule });
            showToast(`${row.horizon} schedule updated.`, 'success');
            await load();
        } catch (err) {
            showToast(err?.response?.data?.error?.message || err.message || 'Schedule update failed', 'danger');
        } finally {
            setBusy(false);
        }
    };

    const rollback = async (row, version) => {
        if (!window.confirm(`Rollback ${row.horizon} to model v${version}?`)) return;
        setBusy(true);
        try {
            await api.post('/v1/admin/ml/rollback', { horizon: row.horizon, version });
            showToast(`Rolled back ${row.horizon} to v${version}.`, 'success');
            await load();
        } catch (err) {
            showToast(err?.response?.data?.error?.message || err.message || 'Rollback failed', 'danger');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h1 className="h3 mb-1">ML Scoring</h1>
                    <div className="text-muted small">Candidate training, active horizon models, promotion status, and reproducibility metadata.</div>
                </div>
                <div className="d-flex gap-2">
                    <select className="form-select" value={horizon} onChange={(e) => setHorizon(e.target.value)}>
                        <option value="1m">1m</option>
                        <option value="3m">3m</option>
                        <option value="6m">6m</option>
                    </select>
                    <button className="btn btn-primary" type="button" onClick={retrain} disabled={busy}>Retrain (queued)</button>
                </div>
            </div>
            {liveProgress ? (
                <div className="alert alert-info py-2" role="status">
                    Training: <strong>{liveProgress.stage}</strong> — {liveProgress.percent ?? 0}%
                </div>
            ) : null}
            {dashboard?.lifecycle ? (
                <div className="card mb-3">
                    <div className="card-header d-flex justify-content-between align-items-center">
                        <span>Lifecycle automation (FEAT-056)</span>
                        <span className={`badge ${dashboard.lifecycle.enabled ? 'text-bg-success' : 'text-bg-secondary'}`}>
                            {dashboard.lifecycle.enabled ? 'STOXLA_ML_LIFECYCLE_ENABLED' : 'lifecycle off'}
                        </span>
                    </div>
                    <div className="card-body small">
                        <p className="mb-2 text-muted">
                            Tick: <code>portfolio:ml-lifecycle-tick</code> ({dashboard.lifecycle.timezone}).
                            Drift trigger: {dashboard.lifecycle.drift_trigger?.enabled ? 'on' : 'off'}.
                            Notifications: {dashboard.lifecycle.notifications?.enabled ? 'on' : 'off'}.
                        </p>
                        <div className="table-responsive">
                            <table className="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Horizon</th>
                                        <th>Schedule</th>
                                        <th>Next run</th>
                                        <th>Due now</th>
                                        <th>Active run</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(dashboard.lifecycle.horizons || []).map((row) => (
                                        <tr key={row.horizon}>
                                            <td>{row.horizon}</td>
                                            <td>
                                                <div className="d-flex gap-2 align-items-center">
                                                    <select
                                                        className="form-select form-select-sm"
                                                        value={row.schedule}
                                                        disabled={busy}
                                                        aria-label={`${row.horizon} schedule`}
                                                        onChange={(e) => updateSchedule(row, row.schedule_enabled, e.target.value)}
                                                    >
                                                        {(row.schedule_options || [row.schedule]).map((option) => <option key={option} value={option}>{option}</option>)}
                                                    </select>
                                                    <button
                                                        type="button"
                                                        className={`btn btn-sm ${row.schedule_enabled ? 'btn-outline-success' : 'btn-outline-secondary'}`}
                                                        disabled={busy}
                                                        onClick={() => updateSchedule(row, !row.schedule_enabled, row.schedule)}
                                                    >
                                                        {row.schedule_enabled ? 'Enabled' : 'Disabled'}
                                                    </button>
                                                </div>
                                            </td>
                                            <td className="small">{row.next_scheduled_at || '—'}</td>
                                            <td>{row.schedule_due_now ? 'yes' : 'no'}</td>
                                            <td>
                                                {row.active_run ? (
                                                    <span>
                                                        {row.active_run.status} (#{row.active_run.id})
                                                        {row.active_run.retry?.attempt ? ` · retry ${row.active_run.retry.attempt}` : ''}
                                                        {row.active_run.cancellation?.requested ? ' · cancellation requested' : ''}
                                                        {row.active_run.failure?.message ? ` · ${row.active_run.failure.message}` : ''}
                                                    </span>
                                                ) : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            ) : null}
            <div className="row g-3">
                {(dashboard?.horizons || []).map((row) => (
                    <div className="col-xl-4" key={row.horizon}>
                        <div className="card h-100">
                            <div className="card-header d-flex justify-content-between">
                                <span>{row.horizon} Horizon</span>
                                <span className="badge text-bg-secondary">{row.candidate_count} candidate</span>
                            </div>
                            <div className="card-body">
                                <div className="small text-muted">Active model</div>
                                <pre className="small">{JSON.stringify(row.active_model || {}, null, 2)}</pre>
                                <div className="small text-muted">Latest training run</div>
                                <pre className="small">{JSON.stringify(row.latest_training_run || {}, null, 2)}</pre>
                                <div className="small text-muted">Latest drift check</div>
                                <pre className="small mb-0">{JSON.stringify(row.latest_drift_check || {}, null, 2)}</pre>
                                {row.retained_models?.length ? (
                                    <div className="mt-3">
                                        <div className="small text-muted mb-1">Retained rollback versions</div>
                                        {row.retained_models.map((model) => (
                                            <div className="d-flex justify-content-between align-items-center small mb-1" key={model.id}>
                                                <span>v{model.version} {model.artifact_ready ? '' : '(artifact unavailable)'}</span>
                                                <button type="button" className="btn btn-outline-warning btn-sm" disabled={busy || !model.artifact_ready} onClick={() => rollback(row, model.version)}>Rollback</button>
                                            </div>
                                        ))}
                                    </div>
                                ) : null}
                                {row.latest_candidate?.id && (
                                    <>
                                        <button
                                            className="btn btn-outline-secondary btn-sm mt-3"
                                            type="button"
                                            onClick={() => loadPromotionReview(row.latest_candidate.id)}
                                            disabled={busy}
                                        >
                                            Promotion review
                                        </button>
                                        <button
                                            className="btn btn-outline-primary btn-sm mt-3 ms-2"
                                            type="button"
                                            onClick={() => promote(row.latest_candidate.id)}
                                            disabled={busy || (reviewModelId === row.latest_candidate.id && promotionReview?.eligible === false)}
                                        >
                                            Promote latest
                                        </button>
                                    </>
                                )}
                                {row.active_model?.id && (
                                    <button className="btn btn-outline-secondary btn-sm mt-3 ms-2" type="button" onClick={() => checkDrift(row.active_model.id)} disabled={busy}>
                                        Check drift
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
            {promotionReview && reviewModelId ? (
                <div className="card mt-3 border-primary">
                    <div className="card-header d-flex justify-content-between align-items-center">
                        <span>Promotion review — model #{reviewModelId}</span>
                        <button type="button" className="btn-close" aria-label="Close" onClick={() => { setPromotionReview(null); setReviewModelId(null); }} />
                    </div>
                    <div className="card-body">
                        <p className="mb-2">
                            Eligible:
                            <span className={`badge ms-2 ${promotionReview.eligible ? 'text-bg-success' : 'text-bg-warning'}`}>
                                {promotionReview.eligible ? 'yes' : 'no'}
                            </span>
                            {promotionReview.artifact_ready === false ? (
                                <span className="badge text-bg-danger ms-2">artifact missing</span>
                            ) : null}
                        </p>
                        <pre className="small mb-0">{JSON.stringify(promotionReview.checks || {}, null, 2)}</pre>
                        {promotionReview.calibration ? (
                            <div className="mt-2 small">
                                <strong>Calibration</strong> ({promotionReview.calibration.method || 'unknown'}):
                                Brier {promotionReview.calibration.calibrated_brier ?? '—'}
                                (uncalibrated {promotionReview.calibration.uncalibrated_brier ?? '—'})
                            </div>
                        ) : null}
                        {promotionReview.delta_vs_active ? (
                            <div className="mt-2 small text-muted">Delta vs active: {JSON.stringify(promotionReview.delta_vs_active)}</div>
                        ) : null}
                        {promotionReview.challenger_sibling ? (
                            <div className="mt-3 border-top pt-3">
                                <div className="small text-muted mb-1">HistGradientBoosting challenger (same training run)</div>
                                <p className="small mb-2">
                                    Model #{promotionReview.challenger_sibling.id} v{promotionReview.challenger_sibling.version}
                                    {' '}
                                    <span className={`badge ${promotionReview.challenger_sibling.eligible ? 'text-bg-success' : 'text-bg-warning'}`}>
                                        {promotionReview.challenger_sibling.eligible ? 'promotion-eligible' : 'not eligible'}
                                    </span>
                                </p>
                                <pre className="small mb-2">{JSON.stringify(promotionReview.challenger_sibling.evaluation_metrics || {}, null, 2)}</pre>
                                <button
                                    type="button"
                                    className="btn btn-outline-primary btn-sm"
                                    disabled={busy || !promotionReview.challenger_sibling.eligible}
                                    onClick={() => promote(promotionReview.challenger_sibling.id)}
                                >
                                    Promote challenger
                                </button>
                            </div>
                        ) : null}
                    </div>
                </div>
            ) : null}
            {runDetail ? (
                <div className="card mt-3 border-secondary">
                    <div className="card-header d-flex justify-content-between align-items-center">
                        <span>Run #{runDetail.id} — challenger &amp; validation</span>
                        <button type="button" className="btn-close" aria-label="Close" onClick={() => setRunDetail(null)} />
                    </div>
                    <div className="card-body">
                        <div className="row g-3">
                            <div className="col-md-6">
                                <div className="small text-muted mb-1">Challenger evidence (HistGradientBoosting vs logistic)</div>
                                <pre className="small mb-0">{JSON.stringify(runDetail.challenger_evidence || { note: 'Not recorded for this run.' }, null, 2)}</pre>
                            </div>
                            <div className="col-md-6">
                                <div className="small text-muted mb-1">Return regressor (Ridge on benchmark-relative return)</div>
                                <pre className="small mb-0">{JSON.stringify(runDetail.return_regressor_evidence || { note: 'Not recorded for this run.' }, null, 2)}</pre>
                            </div>
                            <div className="col-md-12">
                                <div className="small text-muted mb-1">Chronological validation grid</div>
                                <pre className="small mb-0">{JSON.stringify(runDetail.chronological_validation_grid || { note: 'Not recorded for this run.' }, null, 2)}</pre>
                            </div>
                        </div>
                        {runDetail.metrics ? (
                            <div className="mt-3">
                                <div className="small text-muted mb-1">Run metrics</div>
                                <pre className="small mb-0">{JSON.stringify(runDetail.metrics, null, 2)}</pre>
                            </div>
                        ) : null}
                    </div>
                </div>
            ) : null}
            {retentionPlan ? (
                <div className="card mt-3">
                    <div className="card-header d-flex justify-content-between align-items-center">
                        <span>Artifact retention (FEAT-056)</span>
                        <span className={`badge ${retentionPlan.enabled ? 'text-bg-success' : 'text-bg-secondary'}`}>
                            {retentionPlan.enabled ? 'STOXLA_ML_RETENTION_ENABLED' : 'retention off'}
                        </span>
                    </div>
                    <div className="card-body">
                        <p className="text-muted small">
                            Keeps up to {retentionPlan.max_retained_per_horizon} retained (non-active) model artifact(s) per horizon.
                            Lifecycle tick also prunes when retention is enabled. Active models are never deleted.
                        </p>
                        <ul className="small mb-3">
                            {(retentionPlan.horizons || []).map((row) => (
                                <li key={row.horizon}>
                                    <strong>{row.horizon}</strong>: would prune {(row.would_prune || []).length} version(s)
                                    {(row.would_prune || []).length ? ` (v${row.would_prune.map((m) => m.version).join(', v')})` : ''}
                                </li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            className="btn btn-outline-warning btn-sm"
                            disabled={busy || !retentionPlan.enabled}
                            onClick={applyRetention}
                        >
                            Apply prune now
                        </button>
                    </div>
                </div>
            ) : null}
            <div className="card mt-3">
                <div className="card-header">Recent training runs ({horizon})</div>
                <div className="card-body p-0">
                    <table className="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Trigger</th>
                                <th>Stage</th>
                                <th>%</th>
                                <th>Started</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {runs.length === 0 ? (
                                <tr><td colSpan={7} className="text-muted p-3">No runs recorded for this horizon.</td></tr>
                            ) : runs.map((run) => (
                                <tr key={run.id}>
                                    <td>{run.id}</td>
                                    <td>{run.status}</td>
                                    <td>{run.trigger}</td>
                                    <td>{run.progress?.stage || '—'}</td>
                                    <td>{run.progress?.percent ?? '—'}</td>
                                    <td className="small text-muted">{run.started_at || '—'}</td>
                                    <td className="text-end">
                                        {['completed_eligible', 'completed_rejected'].includes(run.status) ? (
                                            <button
                                                type="button"
                                                className="btn btn-outline-secondary btn-sm me-1"
                                                disabled={busy}
                                                onClick={() => loadRunDetail(run.id)}
                                            >
                                                Evidence
                                            </button>
                                        ) : null}
                                        {ACTIVE_RUN_STATUSES.includes(run.status) ? (
                                            <button
                                                type="button"
                                                className="btn btn-outline-danger btn-sm"
                                                disabled={busy}
                                                onClick={() => cancelRun(run.id)}
                                            >
                                                Cancel
                                            </button>
                                        ) : null}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
