import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../api';
import FundamentalInsightsSignals from '../components/FundamentalInsightsSignals';
import StockAutocomplete from '../components/StockAutocomplete';
import { ROUTES } from '../navigation/routes';

function resolveStockFromSearchRows(rows, symbol) {
    const needle = String(symbol || '').toUpperCase();
    const exact = (rows || []).filter((row) => String(row.symbol || '').toUpperCase() === needle);
    if (exact.length === 0) {
        return null;
    }
    return exact.find((row) => row.exchange === 'NSE') || exact[0];
}

export default function FundamentalInsightsPage() {
    const { symbol: routeSymbol } = useParams();
    const navigate = useNavigate();
    const [stock, setStock] = useState(null);
    const [loading, setLoading] = useState(false);
    const [refreshingAi, setRefreshingAi] = useState(false);
    const [error, setError] = useState(null);
    const [snapshot, setSnapshot] = useState(null);

    const loadInsights = useCallback(async (stockId) => {
        setLoading(true);
        setError(null);
        try {
            const params = { include_insights: 1, include_ai_insights: 1 };
            const res = await api.get(`/v1/stocks/${stockId}/fundamentals`, {
                params,
                skipErrorToast: true,
            });
            setSnapshot(res?.data?.data ?? res?.data ?? null);
        } catch (e) {
            setSnapshot(null);
            setError(e?.response?.data?.error?.message || e.message || 'Failed to load fundamental insights');
        } finally {
            setLoading(false);
            setRefreshingAi(false);
        }
    }, []);

    useEffect(() => {
        if (!routeSymbol) {
            setStock(null);
            setSnapshot(null);
            return undefined;
        }
        let cancelled = false;
        (async () => {
            try {
                const res = await api.get('/stocks/search', {
                    params: { q: routeSymbol, limit: 8 },
                    skipErrorToast: true,
                });
                const rows = res?.data?.data ?? res?.data ?? [];
                const match = resolveStockFromSearchRows(rows, routeSymbol);
                if (cancelled) return;
                if (!match?.id) {
                    setStock(null);
                    setError(`No stock found for symbol ${routeSymbol}`);
                    return;
                }
                setStock(match);
                await loadInsights(match.id);
            } catch (e) {
                if (!cancelled) {
                    setError(e?.response?.data?.error?.message || e.message || 'Failed to resolve symbol');
                }
            }
        })();
        return () => { cancelled = true; };
    }, [routeSymbol, loadInsights]);

    const onStockPick = (picked) => {
        if (!picked?.symbol) return;
        navigate(`${ROUTES.FUNDAMENTAL_INSIGHTS}/${picked.symbol}`);
    };

    const onRefreshAi = async () => {
        if (!stock?.id) return;
        setRefreshingAi(true);
        await loadInsights(stock.id);
    };

    const insights = snapshot?.insights ?? null;

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Fundamental insights</h1>
                    <p className="text-muted small mb-0">
                        Material positive, risk, and watch signals (deterministic first, optional AI interpretation).
                        Not investment advice.
                    </p>
                </div>
                {stock?.symbol ? (
                    <Link
                        className="btn btn-outline-secondary btn-sm"
                        to={`${ROUTES.WATCHLIST}/${stock.symbol}`}
                    >
                        Full fundamentals on Watchlist
                    </Link>
                ) : null}
            </div>

            <div className="row g-3">
                <div className="col-lg-4">
                    <label className="form-label small text-muted">Symbol</label>
                    <StockAutocomplete
                        placeholder="Search NSE/BSE symbol…"
                        onSelect={onStockPick}
                        value={routeSymbol || ''}
                    />
                </div>
            </div>

            {error ? (
                <div className="alert alert-warning mt-3 py-2 small">{error}</div>
            ) : null}

            {loading && !snapshot ? (
                <div className="text-muted small mt-3">Loading insights…</div>
            ) : null}

            {stock && snapshot ? (
                <div className="card mt-3">
                    <div className="card-body">
                        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h2 className="h5 mb-0">
                                    {stock.symbol}
                                    <span className="text-muted fw-normal small ms-2">{stock.name}</span>
                                </h2>
                                <div className="text-muted small mt-1">
                                    As of {snapshot.as_of}
                                    {snapshot.freshness?.status
                                        ? ` · Freshness: ${snapshot.freshness.status}`
                                        : ''}
                                </div>
                            </div>
                            <button
                                type="button"
                                className="btn btn-outline-primary btn-sm"
                                disabled={refreshingAi || loading}
                                onClick={onRefreshAi}
                            >
                                {refreshingAi ? 'Refreshing AI…' : 'Refresh AI insights'}
                            </button>
                        </div>
                        {insights ? (
                            <FundamentalInsightsSignals insights={insights} showAiStatus />
                        ) : (
                            <p className="text-muted small mb-0">No insight payload returned for this symbol.</p>
                        )}
                    </div>
                </div>
            ) : !routeSymbol ? (
                <p className="text-muted small mt-3 mb-0">Pick a symbol to load fundamental signals.</p>
            ) : null}
        </div>
    );
}
