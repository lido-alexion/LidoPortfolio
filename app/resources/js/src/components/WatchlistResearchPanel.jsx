import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';

function MetricGrid({ rows }) {
    return (
        <div className="row g-2">
            {rows.map(([label, value]) => (
                <div className="col-6" key={label}>
                    <div className="text-muted small">{label}</div>
                    <div className="fw-semibold">{value ?? '—'}</div>
                </div>
            ))}
        </div>
    );
}

function fmt(v, suffix = '') {
    if (v == null || v === '') return '—';
    const n = Number(v);
    if (Number.isNaN(n)) return String(v);
    return `${n}${suffix}`;
}

/**
 * Watchlist research tabs — Stock Analytics / Evaluation Profile / Recommendation Preview (SD-031 / F137).
 * Recommendation Preview uses the dedicated F137 contract (strategy_id required).
 */
export default function WatchlistResearchPanel({ stockId }) {
    const [tab, setTab] = useState('stock');
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState(null);
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState(null);
    const [previewError, setPreviewError] = useState(null);

    useEffect(() => {
        if (!stockId) {
            setData(null);
            setPreview(null);
            return undefined;
        }
        let cancelled = false;
        setLoading(true);
        setError(null);
        setPreviewError(null);

        (async () => {
            try {
                const strategyRes = await api.get('/v1/strategy', { skipErrorToast: true });
                const strategyId = strategyRes?.data?.data?.id ?? strategyRes?.data?.id;
                if (!strategyId) {
                    throw new Error('No active strategy for this portfolio. Open Strategy and select one.');
                }

                const [researchRes, previewRes] = await Promise.all([
                    api.get(`/v1/analytics/stocks/${stockId}/evaluation-profile`, { skipErrorToast: true })
                        .then(async (evalRes) => {
                            const stockRes = await api.get(`/v1/analytics/stocks/${stockId}`, { skipErrorToast: true });
                            return {
                                stock_analytics: stockRes?.data?.data ?? stockRes?.data ?? null,
                                evaluation_profile: evalRes?.data?.data ?? evalRes?.data ?? null,
                            };
                        }),
                    api.get(`/v1/analytics/stocks/${stockId}/recommendation-preview`, {
                        params: { strategy_id: strategyId },
                        skipErrorToast: true,
                    }),
                ]);

                if (cancelled) return;
                setData(researchRes);
                setPreview(previewRes?.data?.data ?? previewRes?.data ?? null);
            } catch (e) {
                if (cancelled) return;
                const msg = e?.response?.data?.error?.message || e.message || 'Failed to load analytics';
                const status = e?.response?.status;
                if (status === 422 || e?.response?.data?.error?.code?.includes?.('STRATEGY')) {
                    setPreviewError(msg);
                    // Still try stock + eval without preview
                    try {
                        const [stockRes, evalRes] = await Promise.all([
                            api.get(`/v1/analytics/stocks/${stockId}`, { skipErrorToast: true }),
                            api.get(`/v1/analytics/stocks/${stockId}/evaluation-profile`, { skipErrorToast: true }),
                        ]);
                        if (!cancelled) {
                            setData({
                                stock_analytics: stockRes?.data?.data ?? null,
                                evaluation_profile: evalRes?.data?.data ?? null,
                            });
                        }
                    } catch (inner) {
                        if (!cancelled) setError(inner?.response?.data?.error?.message || inner.message || msg);
                    }
                } else {
                    setError(msg);
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();

        return () => { cancelled = true; };
    }, [stockId]);

    if (!stockId) return null;

    const stock = data?.stock_analytics;
    const evalProfile = data?.evaluation_profile;
    const exec = preview?.execution || preview;
    const researchMeta = preview?.research || preview;
    const available = preview?.available !== false && exec?.recommendation != null;

    return (
        <div className="card">
            <div className="card-header py-2">
                <div className="btn-group btn-group-sm" role="group" aria-label="Research tabs">
                    {[
                        ['stock', 'Stock Analytics'],
                        ['evaluation', 'Evaluation Profile'],
                        ['recommendation', 'Recommendation Preview'],
                    ].map(([id, label]) => (
                        <button
                            key={id}
                            type="button"
                            className={`btn ${tab === id ? 'btn-primary' : 'btn-outline-primary'}`}
                            onClick={() => setTab(id)}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>
            <div className="card-body">
                {loading ? <div className="text-muted small">Loading research analytics…</div> : null}
                {error ? <div className="alert alert-warning py-2 small mb-0">{error}</div> : null}
                {!loading && !error && tab === 'stock' && stock ? (
                    <MetricGrid rows={[
                        ['Beta', fmt(stock.beta)],
                        ['Historical Volatility %', fmt(stock.historical_volatility_pct, '%')],
                        ['Relative Strength', fmt(stock.relative_strength)],
                        ['Trend Strength', fmt(stock.trend_strength)],
                        ['Max Drawdown %', fmt(stock.maximum_drawdown_pct, '%')],
                        ['Current Drawdown %', fmt(stock.current_drawdown_pct, '%')],
                        ['52w High Distance %', fmt(stock.distance_52w_high_pct, '%')],
                        ['52w Low Distance %', fmt(stock.distance_52w_low_pct, '%')],
                        ['Avg Daily Volume', stock.average_daily_volume != null ? Number(stock.average_daily_volume).toLocaleString() : '—'],
                        ['Liquidity', stock.liquidity_rating],
                    ]}
                    />
                ) : null}
                {!loading && !error && tab === 'evaluation' && evalProfile ? (
                    evalProfile.available ? (
                        <MetricGrid rows={[
                            ['Overall Evaluation Score', fmt(evalProfile.overall_evaluation_score)],
                            ['Momentum Score', fmt(evalProfile.momentum_score)],
                            ['Trend Score', fmt(evalProfile.trend_score)],
                            ['Breakout Score', fmt(evalProfile.breakout_score)],
                            ['Volume Score', fmt(evalProfile.volume_score)],
                            ['Risk Score', fmt(evalProfile.risk_score)],
                            ['Sector Strength', fmt(evalProfile.sector_strength)],
                            ['Market Alignment', fmt(evalProfile.market_alignment)],
                            ['Confidence', fmt(evalProfile.confidence)],
                            ['Rank', evalProfile.rank ?? '—'],
                        ]}
                        />
                    ) : (
                        <p className="text-muted small mb-0">
                            {evalProfile.message || 'No evaluation profile yet.'}
                            {' '}
                            <Link to="/candidates">Run Discovery</Link>
                            {' '}
                            (includes evaluation).
                        </p>
                    )
                ) : null}
                {!loading && tab === 'recommendation' ? (
                    <div className="d-grid gap-2">
                        {previewError ? (
                            <div className="alert alert-warning py-2 small mb-0">{previewError}</div>
                        ) : null}
                        {!previewError && preview && !available ? (
                            <>
                                <p className="small text-muted mb-1">
                                    Recommendation is not executable for this stock under the selected strategy.
                                </p>
                                {(preview.unavailable_reasons || []).length > 0 ? (
                                    <ul className="small mb-0 ps-3">
                                        {preview.unavailable_reasons.map((r, i) => (
                                            <li key={r.code || i}>
                                                {r.message || r.code || String(r)}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                {exec?.evaluation_cycle_id ? (
                                    <div className="text-muted small">Evaluation cycle #{exec.evaluation_cycle_id}</div>
                                ) : null}
                            </>
                        ) : null}
                        {!previewError && preview && available ? (
                            <>
                                <MetricGrid rows={[
                                    ['Recommendation', exec?.recommendation || '—'],
                                    ['Recommendation Score', fmt(exec?.recommendation_score)],
                                    ['Strategy', exec?.strategy?.name
                                        ? `${exec.strategy.name} ${exec.strategy.version_label || ''}`.trim()
                                        : '—'],
                                    ['Suggested Allocation %', fmt(exec?.suggested_allocation_pct, '%')],
                                    ['Confidence (0–1)', fmt(researchMeta?.confidence)],
                                    ['Source', exec?.source || '—'],
                                    ['Evaluation cycle', exec?.evaluation_cycle_id ?? '—'],
                                ]}
                                />
                                {(researchMeta?.eligibility_sources || []).length > 0 ? (
                                    <div>
                                        <div className="text-muted small mb-1">
                                            Eligibility sources
                                            {researchMeta?.eligibility_required ? ' (required by strategy)' : ' (metadata)'}
                                        </div>
                                        <ul className="small mb-0 ps-3">
                                            {researchMeta.eligibility_sources.map((s, i) => (
                                                <li key={s.screener_id || i}>
                                                    {(s.name || s.screener_name || 'Screener')}
                                                    {s.status ? ` — ${s.status}` : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : null}
                                {researchMeta?.reason_summary ? (
                                    <p className="small text-muted mb-0">{researchMeta.reason_summary}</p>
                                ) : null}
                                {researchMeta?.recommendation_id ? (
                                    <Link className="small" to="/recommendations">Open recommendations</Link>
                                ) : null}
                            </>
                        ) : null}
                        {!previewError && !preview && !loading ? (
                            <p className="text-muted small mb-0">No recommendation preview loaded.</p>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
