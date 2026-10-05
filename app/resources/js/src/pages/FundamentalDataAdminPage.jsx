import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { showToast } from '../toast';

export default function FundamentalDataAdminPage() {
    const [status, setStatus] = useState(null);
    const [form, setForm] = useState({
        quarterly_freshness_months: 5,
        annual_freshness_months: 15,
        request_delay_ms: 750,
        max_attempts: 3,
        paused: false,
        nse_official_fallback_enabled: false,
        bse_official_fallback_enabled: false,
        ai_insights_primary_provider: '',
    });
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
                nse_official_fallback_enabled: Boolean(data.data.settings.nse_official_fallback_enabled),
                bse_official_fallback_enabled: Boolean(data.data.settings.bse_official_fallback_enabled),
                ai_insights_primary_provider: data.data.settings.ai_insights_primary_provider || '',
            });
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const save = async () => {
        setBusy(true);
        try {
            await api.put('/v1/admin/fundamentals/settings', {
                ...form,
                ai_insights_primary_provider: form.ai_insights_primary_provider || null,
            });
            showToast('Fundamental settings saved.', 'success');
            await load();
        } finally {
            setBusy(false);
        }
    };

    const testAi = async (provider) => {
        setBusy(true);
        try {
            const { data } = await api.post('/v1/admin/fundamentals/ai-insights/test', { provider });
            if (data?.data?.ok) {
                showToast(`AI provider test succeeded (${data.data.provider || provider || 'chain'}).`, 'success');
            } else {
                showToast(data?.data?.error_message || 'AI provider test failed.', 'warning');
            }
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
                            <div className="border-top mt-3 pt-3">
                                <h2 className="h6">Historical bootstrap exchange fallbacks</h2>
                                <p className="small text-muted">Yahoo stays primary. NSE/BSE are tried only when Yahoo returns no usable facts.</p>
                                {['nse', 'bse'].map((exchange) => {
                                    const field = `${exchange}_official_fallback_enabled`;
                                    const route = status?.exchange_fallbacks?.[exchange];
                                    return <label className="form-check mb-2" key={exchange}>
                                        <input className="form-check-input" type="checkbox" checked={form[field]} disabled={busy || (!route?.configured && !form[field])} onChange={(e) => setForm({ ...form, [field]: e.target.checked })} />
                                        <span className="form-check-label">Enable {exchange.toUpperCase()} fallback <span className="text-muted small">({route?.configured ? 'approved route configured' : 'no approved route configured'})</span></span>
                                    </label>;
                                })}
                            </div>
                            <button className="btn btn-outline-primary mt-3" type="button" onClick={save} disabled={busy}>Save</button>
                        </div>
                    </div>
                </div>
                <div className="col-lg-8">
                    <div className="card mb-3">
                        <div className="card-header">AI insights providers (FEAT-062)</div>
                        <div className="card-body">
                            <p className="text-muted small">
                                Requires <code>FUNDAMENTALS_AI_INSIGHTS_ENABLED</code> and provider API keys in server env.
                                Primary order is persisted here; secrets stay server-side.
                            </p>
                            <div className="mb-3">
                                <span className="badge text-bg-secondary me-2">
                                    Enabled: {status?.ai_insights?.enabled ? 'yes' : 'no'}
                                </span>
                                <span className="badge text-bg-light border">
                                    Effective primary: {status?.ai_insights?.primary_provider || '—'}
                                    {' '}
                                    ({status?.ai_insights?.primary_provider_source || 'env'})
                                </span>
                            </div>
                            <label className="form-label">Primary provider override</label>
                            <select
                                className="form-select mb-3"
                                value={form.ai_insights_primary_provider}
                                onChange={(e) => setForm({ ...form, ai_insights_primary_provider: e.target.value })}
                            >
                                <option value="">Use env default ({status?.ai_insights?.primary_provider_source === 'env' ? (status?.ai_insights?.primary_provider || 'gemini') : 'gemini'})</option>
                                <option value="gemini">Gemini (primary)</option>
                                <option value="codex">Codex / OpenAI-compatible (primary)</option>
                            </select>
                            <table className="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Provider</th>
                                        <th>Role</th>
                                        <th>Configured</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(status?.ai_insights?.providers || []).map((row) => (
                                        <tr key={row.id}>
                                            <td className="text-capitalize">{row.id}</td>
                                            <td>{row.role}</td>
                                            <td>{row.configured ? 'Yes' : 'No'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="d-flex flex-wrap gap-2 mt-3">
                                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy} onClick={() => testAi('gemini')}>
                                    Test Gemini
                                </button>
                                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy} onClick={() => testAi('codex')}>
                                    Test Codex
                                </button>
                                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busy} onClick={() => testAi(null)}>
                                    Test failover chain
                                </button>
                            </div>
                            {status?.ai_insights?.usage ? (
                                <p className="text-muted small mt-2 mb-0">
                                    Today: {status.ai_insights.usage.today?.global_invocations ?? 0} invocations
                                    ({status.ai_insights.usage.today?.ok_invocations ?? 0} ok).
                                    Tokens in/out: {status.ai_insights.usage.today?.input_tokens ?? 0}
                                    / {status.ai_insights.usage.today?.output_tokens ?? 0}.
                                    Est. spend (USD):{' '}
                                    {typeof status.ai_insights.usage.today?.estimated_cost_usd === 'number'
                                        ? status.ai_insights.usage.today.estimated_cost_usd.toFixed(4)
                                        : '—'}.
                                    Limits: global {status.ai_insights.usage.limits?.daily_global_max || '∞'}
                                    / user {status.ai_insights.usage.limits?.daily_per_user_max || '∞'} per day.
                                </p>
                            ) : null}
                            <p className="text-muted small mt-2 mb-0">
                                Save freshness policy below to persist provider preference (same settings API).
                            </p>
                        </div>
                    </div>
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
