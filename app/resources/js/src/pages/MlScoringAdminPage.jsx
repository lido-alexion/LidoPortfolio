import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

export default function MlScoringAdminPage() {
    const [dashboard, setDashboard] = useState(null);
    const [horizon, setHorizon] = useState('3m');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const { data } = await api.get('/v1/admin/ml');
        setDashboard(data.data);
    }, []);

    useEffect(() => { load(); }, [load]);

    const retrain = async () => {
        setBusy(true);
        try {
            await api.post('/v1/admin/ml/retrain', { horizon });
            showToast(`Candidate ${horizon} model created.`, 'success');
            await load();
        } finally {
            setBusy(false);
        }
    };

    const promote = async (modelId) => {
        setBusy(true);
        try {
            await api.post(`/v1/admin/ml/models/${modelId}/promote`);
            showToast('Model promoted.', 'success');
            await load();
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
                    <button className="btn btn-primary" type="button" onClick={retrain} disabled={busy}>Retrain</button>
                </div>
            </div>
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
                                <pre className="small mb-0">{JSON.stringify(row.latest_training_run || {}, null, 2)}</pre>
                                {row.latest_candidate?.id && (
                                    <button className="btn btn-outline-primary btn-sm mt-3" type="button" onClick={() => promote(row.latest_candidate.id)} disabled={busy}>
                                        Promote latest
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
