import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import FundamentalInsightsSignals from './FundamentalInsightsSignals';
import FundamentalMetricMiniChart from './FundamentalMetricMiniChart';
import FundamentalStatementTables from './FundamentalStatementTables';
import { formatCoveragePeriods, formatFundamentalMetric } from '../utils/fundamentalDisplay';
import { fundamentalDefinition } from '../utils/fundamentalDefinitions';
import { advancedFundamentalsPreferenceKey } from '../utils/fundamentalPreference';
import { useAuth } from '../context/AuthContext';

function MetricGrid({ rows }) {
    return (
        <div className="row g-2">
            {rows.map((inputRow, index) => {
                const row = Array.isArray(inputRow)
                    ? { id: inputRow[0], label: inputRow[0], value: inputRow[1] }
                    : inputRow;
                return (
                <div className="col-6" key={row.id || row.label || index}>
                    <div className="text-muted small">
                        {row.label}
                        <span
                            className="ms-1 text-secondary"
                            title={row.definition}
                            aria-label={`${row.label}: ${row.definition}`}
                            role="img"
                        >
                            ⓘ
                        </span>
                    </div>
                    <div className="fw-semibold">{row.value ?? '—'}</div>
                    {row.provenance ? (
                        <div className="text-muted small" title={row.provenance.detail}>
                            {row.provenance.label}
                        </div>
                    ) : null}
                </div>
                );
            })}
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
    const { user } = useAuth();
    const [tab, setTab] = useState('stock');
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState(null);
    const [fundamentals, setFundamentals] = useState(null);
    const [fundamentalsHistory, setFundamentalsHistory] = useState(null);
    const [fundamentalsRevenueSeries, setFundamentalsRevenueSeries] = useState(null);
    const [fundamentalsPeSeries, setFundamentalsPeSeries] = useState(null);
    const [fundamentalsPbSeries, setFundamentalsPbSeries] = useState(null);
    const [fundamentalsInsights, setFundamentalsInsights] = useState(null);
    const [fundamentalsCadence, setFundamentalsCadence] = useState('quarterly');
    const [valuationChartFrequency, setValuationChartFrequency] = useState('monthly');
    const [fundamentalsLoading, setFundamentalsLoading] = useState(false);
    const [fundamentalsError, setFundamentalsError] = useState(null);
    const [fundamentalsFetchBusy, setFundamentalsFetchBusy] = useState(false);
    const [fundamentalsFetchMessage, setFundamentalsFetchMessage] = useState(null);
    const [fundamentalsRefreshKey, setFundamentalsRefreshKey] = useState(0);
    const [mlInsights, setMlInsights] = useState(null);
    const [mlLoading, setMlLoading] = useState(false);
    const [mlRefreshing, setMlRefreshing] = useState(false);
    const [mlError, setMlError] = useState(null);
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

    useEffect(() => {
        if (!stockId || tab !== 'fundamentals') {
            return undefined;
        }
        let cancelled = false;
        setFundamentalsLoading(true);
        setFundamentalsError(null);
        setFundamentalsHistory(null);
        setFundamentalsRevenueSeries(null);
        setFundamentalsPeSeries(null);
        setFundamentalsPbSeries(null);
        setFundamentalsInsights(null);
        Promise.all([
            api.get(`/v1/stocks/${stockId}/fundamentals`, { params: { include_insights: 1 }, skipErrorToast: true }),
            api.get(`/v1/stocks/${stockId}/fundamentals/history`, {
                params: { cadence: fundamentalsCadence },
                skipErrorToast: true,
            }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/revenue/history`, {
                params: { range: '5y' },
                skipErrorToast: true,
            }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/pe/history`, {
                params: { range: '5y', frequency: valuationChartFrequency },
                skipErrorToast: true,
            }),
            api.get(`/v1/stocks/${stockId}/fundamentals/metrics/pb/history`, {
                params: { range: '5y', frequency: valuationChartFrequency },
                skipErrorToast: true,
            }),
        ])
            .then(([snapRes, histRes, revRes, peRes, pbRes]) => {
                if (!cancelled) {
                    const snap = snapRes?.data?.data ?? snapRes?.data ?? null;
                    setFundamentals(snap);
                    setFundamentalsInsights(snap?.insights ?? null);
                    setFundamentalsHistory(histRes?.data?.data ?? histRes?.data ?? null);
                    setFundamentalsRevenueSeries(revRes?.data?.data ?? revRes?.data ?? null);
                    setFundamentalsPeSeries(peRes?.data?.data ?? peRes?.data ?? null);
                    setFundamentalsPbSeries(pbRes?.data?.data ?? pbRes?.data ?? null);
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setFundamentalsError(e?.response?.data?.error?.message || e.message || 'Failed to load fundamentals');
                }
            })
            .finally(() => {
                if (!cancelled) setFundamentalsLoading(false);
            });

        return () => { cancelled = true; };
    }, [stockId, tab, fundamentalsCadence, valuationChartFrequency, fundamentalsRefreshKey]);

    useEffect(() => {
        if (!stockId || tab !== 'ml') {
            return undefined;
        }
        let cancelled = false;
        setMlLoading(true);
        setMlError(null);
        api.get(`/v1/stocks/${stockId}/ml-insights`, { skipErrorToast: true })
            .then((res) => {
                if (!cancelled) {
                    setMlInsights(res?.data?.data ?? res?.data ?? null);
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setMlError(e?.response?.data?.error?.message || e.message || 'Failed to load ML scores');
                }
            })
            .finally(() => {
                if (!cancelled) setMlLoading(false);
            });

        return () => { cancelled = true; };
    }, [stockId, tab]);

    const refreshMlInsights = async () => {
        if (!stockId) return;
        setMlRefreshing(true);
        setMlError(null);
        try {
            const res = await api.post(`/v1/stocks/${stockId}/ml-insights/refresh`, {}, { skipErrorToast: true });
            setMlInsights(res?.data?.data ?? res?.data ?? null);
        } catch (e) {
            setMlError(e?.response?.data?.error?.message || e.message || 'Failed to refresh ML scores');
        } finally {
            setMlRefreshing(false);
        }
    };

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
                        ['fundamentals', 'Fundamentals'],
                        ['ml', 'ML scores'],
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
                {tab === 'fundamentals' ? (
                    fundamentalsLoading ? (
                        <div className="text-muted small">Loading fundamentals…</div>
                    ) : fundamentalsError ? (
                        <div className="alert alert-warning py-2 small mb-0">{fundamentalsError}</div>
                    ) : fundamentals ? (
                        <div className="d-grid gap-3">
                            <div className="d-flex flex-wrap gap-3 small text-muted">
                                <span>
                                    As of {fundamentals.as_of}
                                    {fundamentals.market_price != null ? ` · Price ₹${Number(fundamentals.market_price).toFixed(2)}` : ''}
                                </span>
                                {fundamentals.freshness?.status ? (
                                    <span className="badge text-bg-secondary">
                                        Freshness: {fundamentals.freshness.status}
                                    </span>
                                ) : null}
                            </div>
                            <MetricGrid rows={(fundamentals.summary || []).map((row) => ({
                                id: row.id,
                                label: row.label,
                                value: formatFundamentalMetric(row.value, row.id),
                                definition: fundamentalDefinition(row.id, row.basis),
                                provenance: row.provenance ? {
                                    label: row.provenance.source_label || 'Source unavailable',
                                    detail: `${row.provenance.derived ? 'Derived' : 'Reported'} · ${row.provenance.basis || row.basis || 'current'}${row.provenance.latest_period_end ? ` · period ${row.provenance.latest_period_end}` : ''}`,
                                } : null,
                            }))}
                            />
                            {fundamentalsInsights ? (
                                <FundamentalInsightsSignals insights={fundamentalsInsights} />
                            ) : null}
                            <FundamentalMetricMiniChart
                                label={fundamentalsRevenueSeries?.label ? `${fundamentalsRevenueSeries.label} trend` : 'Revenue (TTM) trend'}
                                points={fundamentalsRevenueSeries?.points}
                            />
                            <div className="d-flex flex-wrap align-items-center gap-2 small">
                                <span className="text-muted">Valuation chart frequency</span>
                                <select
                                    className="form-select form-select-sm w-auto"
                                    value={valuationChartFrequency}
                                    onChange={(event) => setValuationChartFrequency(event.target.value)}
                                    aria-label="Valuation chart frequency"
                                >
                                    <option value="monthly">Monthly (default)</option>
                                    <option value="daily">Daily</option>
                                    <option value="quarterly">Quarterly (PIT TTM)</option>
                                </select>
                            </div>
                            <FundamentalMetricMiniChart
                                label={fundamentalsPeSeries?.label ? `${fundamentalsPeSeries.label} trend` : 'P/E (TTM) trend'}
                                points={fundamentalsPeSeries?.points}
                            />
                            <FundamentalMetricMiniChart
                                label={fundamentalsPbSeries?.label ? `${fundamentalsPbSeries.label} trend` : 'P/B (TTM) trend'}
                                points={fundamentalsPbSeries?.points}
                            />
                            {fundamentals.coverage ? (
                                <p className="text-muted small mb-0">
                                    Quarterly history: {formatCoveragePeriods(fundamentals.coverage, 'quarterly')}
                                    {' · '}
                                    Annual history: {formatCoveragePeriods(fundamentals.coverage, 'annual')}
                                </p>
                            ) : null}
                            {!(fundamentals.summary || []).some((row) => row.value !== null && row.value !== undefined) ? (
                                <div className="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        className="btn btn-outline-primary btn-sm"
                                        disabled={fundamentalsFetchBusy}
                                        onClick={async () => {
                                            setFundamentalsFetchBusy(true);
                                            setFundamentalsFetchMessage(null);
                                            try {
                                                await api.post(`/v1/stocks/${stockId}/fundamentals/manual-fetch`, {}, { skipErrorToast: true });
                                                setFundamentalsFetchMessage('Fetch completed. Refreshing data…');
                                                setFundamentalsRefreshKey((value) => value + 1);
                                            } catch (e) {
                                                setFundamentalsFetchMessage(e?.response?.data?.error?.message || 'Fetch could not be completed.');
                                            } finally {
                                                setFundamentalsFetchBusy(false);
                                            }
                                        }}
                                    >
                                        {fundamentalsFetchBusy ? 'Fetching…' : 'Fetch fundamentals for this stock'}
                                    </button>
                                    <span className="text-muted small">This checks Yahoo first, then the approved exchange feed for this stock only.</span>
                                    {fundamentalsFetchMessage ? <span className="small" role="status">{fundamentalsFetchMessage}</span> : null}
                                </div>
                            ) : null}
                            <FundamentalStatementTables
                                history={fundamentalsHistory}
                                cadence={fundamentalsCadence}
                                onCadenceChange={setFundamentalsCadence}
                                preferenceKey={advancedFundamentalsPreferenceKey(user?.id)}
                            />
                        </div>
                    ) : (
                        <p className="text-muted small mb-0">No fundamentals loaded.</p>
                    )
                ) : null}
                {tab === 'ml' ? (
                    mlLoading ? (
                        <div className="text-muted small">Loading ML scores…</div>
                    ) : mlError ? (
                        <div className="alert alert-warning py-2 small mb-0">{mlError}</div>
                    ) : mlInsights ? (
                        <div className="d-grid gap-3">
                            <p className="text-muted small mb-0">{mlInsights.disclaimer}</p>
                            <div className="table-responsive">
                                <table className="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr className="small text-muted">
                                            <th>Horizon</th>
                                            <th>Score</th>
                                            <th>Confidence</th>
                                            <th>Exp. vs benchmark</th>
                                            <th>As of</th>
                                            <th>Model</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(mlInsights.horizons || []).map((row) => (
                                            <tr key={row.horizon}>
                                                <td>{row.horizon}</td>
                                                <td className="fw-semibold">
                                                    {row.prediction?.score != null
                                                        ? Number(row.prediction.score).toFixed(3)
                                                        : '—'}
                                                </td>
                                                <td>
                                                    {row.prediction?.confidence != null
                                                        ? Number(row.prediction.confidence).toFixed(3)
                                                        : '—'}
                                                </td>
                                                <td className="small">
                                                    {row.prediction?.expected_benchmark_relative_return_pct != null
                                                        ? `${Number(row.prediction.expected_benchmark_relative_return_pct).toFixed(2)}%`
                                                        : '—'}
                                                </td>
                                                <td className="small text-muted">{row.prediction?.as_of ?? '—'}</td>
                                                <td className="small text-muted">
                                                    {row.active_model
                                                        ? `v${row.active_model.version}`
                                                        : 'No active model'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-primary"
                                    disabled={mlRefreshing}
                                    onClick={refreshMlInsights}
                                >
                                    {mlRefreshing ? 'Refreshing…' : 'Refresh scores'}
                                </button>
                            </div>
                            {(mlInsights.horizons || []).some((h) => h.prediction?.explanations?.top_positive?.length) ? (
                                <div className="small">
                                    <div className="text-muted mb-1">Top positive drivers (3m)</div>
                                    <ul className="mb-0 ps-3">
                                        {(mlInsights.horizons.find((h) => h.horizon === '3m')?.prediction?.explanations?.top_positive || [])
                                            .slice(0, 3)
                                            .map((item, idx) => (
                                                <li key={item.feature || idx}>{item.feature || item.label || 'Feature'}</li>
                                            ))}
                                    </ul>
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <p className="text-muted small mb-0">No ML insights loaded.</p>
                    )
                ) : null}
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
