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
 * Watchlist research tabs — Stock Analytics / Evaluation Profile / Recommendation Preview (SD-031).
 */
export default function WatchlistResearchPanel({ stockId }) {
    const [tab, setTab] = useState('stock');
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!stockId) {
            setData(null);
            return undefined;
        }
        let cancelled = false;
        setLoading(true);
        setError(null);
        api.get(`/v1/analytics/stocks/${stockId}/research`)
            .then(({ data: res }) => {
                if (!cancelled) setData(res?.data || null);
            })
            .catch((e) => {
                if (!cancelled) setError(e?.response?.data?.error?.message || e.message || 'Failed to load analytics');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
    }, [stockId]);

    if (!stockId) return null;

    const stock = data?.stock_analytics;
    const evalProfile = data?.evaluation_profile;
    const preview = data?.recommendation_preview;

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
                            {' → '}
                            <Link to="/evaluations">Evaluation</Link>
                        </p>
                    )
                ) : null}
                {!loading && !error && tab === 'recommendation' && preview ? (
                    <div className="d-grid gap-2">
                        <MetricGrid rows={[
                            ['Recommendation', preview.recommendation || '—'],
                            ['Recommendation Score', fmt(preview.recommendation_score)],
                            ['Strategy', preview.strategy?.name ? `${preview.strategy.name} ${preview.strategy.version_label || ''}`.trim() : '—'],
                            ['Suggested Allocation %', fmt(preview.suggested_allocation_pct, '%')],
                            ['Confidence', fmt(preview.confidence)],
                            ['Source', preview.source || '—'],
                        ]}
                        />
                        {(preview.eligibility_sources || []).length > 0 ? (
                            <div>
                                <div className="text-muted small mb-1">Eligibility sources</div>
                                <ul className="small mb-0 ps-3">
                                    {preview.eligibility_sources.map((s, i) => (
                                        <li key={s.screener_id || i}>
                                            {(s.name || s.screener_name || 'Screener')}
                                            {s.status ? ` — ${s.status}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                        {preview.reason_summary ? (
                            <p className="small text-muted mb-0">{preview.reason_summary}</p>
                        ) : null}
                        {preview.recommendation_id ? (
                            <Link className="small" to="/recommendations">Open recommendations</Link>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
