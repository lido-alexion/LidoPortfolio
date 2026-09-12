import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

export default function FundamentalDataAdminPage() {
    const [status, setStatus] = useState(null);
    const [form, setForm] = useState({ quarterly_freshness_months: 5, annual_freshness_months: 15, request_delay_ms: 750, max_attempts: 3, paused: false });
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const { data } = await api.get('/v1/admin/fundamentals');
        setStatus(data.data);
        if (data.data?.settings) {
            setForm({
                quarterly_freshness_months: data.data.settings.quarterly_freshness_months,
                annual_freshness_months: data.data.settings.annual_freshness_months,
                request_delay_ms: data.data.settings.request_delay_ms,
                max_attempts: data.data.settings.max_attempts,
                paused: Boolean(data.data.settings.paused),
            });
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const save = async () => {
        setBusy(true);
        try {
            await api.put('/v1/admin/fundamentals/settings', form);
            showToast('Fundamental freshness policy saved.', 'success');
            await load();
        } finally {
            setBusy(false);
        }
    };

    const run = async () => {
        setBusy(true);
        try {
            await api.post('/v1/admin/fundamentals/runs', { limit: 20, process_now: true });
            showToast('Fundamental update slice completed.', 'success');
            await load();
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h1 className="h3 mb-1">Fundamental Data</h1>
                    <div className="text-muted small">Provider status, freshness policy, coverage, and incremental update control.</div>
                </div>
                <button className="btn btn-primary" type="button" onClick={run} disabled={busy}>Run slice</button>
            </div>

            <div className="row g-3">
                <div className="col-lg-4">
                    <div className="card">
                        <div className="card-header">Freshness Policy</div>
                        <div className="card-body">
                            <label className="form-label">Quarterly freshness months</label>
                            <input className="form-control mb-3" type="number" min="1" max="36" value={form.quarterly_freshness_months} onChange={(e) => setForm({ ...form, quarterly_freshness_months: Number(e.target.value) })} />
                            <label className="form-label">Annual freshness months</label>
                            <input className="form-control mb-3" type="number" min="1" max="60" value={form.annual_freshness_months} onChange={(e) => setForm({ ...form, annual_freshness_months: Number(e.target.value) })} />
                            <label className="form-label">Request delay ms</label>
                            <input className="form-control mb-3" type="number" min="0" max="60000" value={form.request_delay_ms} onChange={(e) => setForm({ ...form, request_delay_ms: Number(e.target.value) })} />
                            <label className="form-label">Max attempts</label>
                            <input className="form-control mb-3" type="number" min="1" max="10" value={form.max_attempts} onChange={(e) => setForm({ ...form, max_attempts: Number(e.target.value) })} />
                            <label className="form-check">
                                <input className="form-check-input" type="checkbox" checked={form.paused} onChange={(e) => setForm({ ...form, paused: e.target.checked })} />
                                <span className="form-check-label">Pause updater</span>
                            </label>
                            <button className="btn btn-outline-primary mt-3" type="button" onClick={save} disabled={busy}>Save</button>
                        </div>
                    </div>
                </div>
                <div className="col-lg-8">
                    <div className="card mb-3">
                        <div className="card-header">Latest Run</div>
                        <div className="card-body">
                            <pre className="mb-0 small">{JSON.stringify(status?.latest_run || {}, null, 2)}</pre>
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-header">Coverage</div>
                        <div className="card-body">
                            <div className="row g-3">
                                {Object.entries(status?.coverage || {}).map(([cadence, row]) => (
                                    <div className="col-md-6" key={cadence}>
                                        <div className="border rounded p-3 h-100">
                                            <div className="fw-semibold text-capitalize">{cadence}</div>
                                            <div className="display-6">{row.stocks || 0}</div>
                                            <div className="small text-muted">Latest period: {row.latest_period_end || 'none'}</div>
                                            <div className="small text-muted">Latest available: {row.latest_availability_date || 'none'}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
