import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
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
    const [resolvedStock, setResolvedStock] = useState(null);
    const [read, setRead] = useState(null);
    const [refreshingAi, setRefreshingAi] = useState(false);
    const [retryKey, setRetryKey] = useState(0);
    const routeIdentityRef = useRef(routeSymbol || '');
    const routeEpochRef = useRef(0);
    const readGenerationRef = useRef(0);
    const mountedRef = useRef(false);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            readGenerationRef.current += 1;
        };
    }, []);

    useLayoutEffect(() => {
        routeIdentityRef.current = routeSymbol || '';
        routeEpochRef.current += 1;
        readGenerationRef.current += 1;
    }, [routeSymbol]);

    const loadInsights = useCallback(async (stockId, symbol, routeEpoch, generation) => {
        const isCurrent = () => routeEpochRef.current === routeEpoch
            && routeIdentityRef.current === symbol
            && mountedRef.current
            && readGenerationRef.current === generation;
        if (!isCurrent()) return;
        setRead(previous => ({
            symbol,
            stockId,
            state: 'loading',
            snapshot: previous?.symbol === symbol && previous?.stockId === stockId ? previous.snapshot : null,
            error: null,
        }));
        try {
            const params = { include_insights: 1, include_ai_insights: 1 };
            const res = await api.get(`/v1/stocks/${stockId}/fundamentals`, {
                params,
                skipErrorToast: true,
            });
            if (!isCurrent()) return;
            const snapshot = res?.data?.data ?? res?.data ?? null;
            if (snapshot == null) {
                setRead(previous => ({
                    symbol,
                    stockId,
                    state: 'unavailable',
                    snapshot: previous?.symbol === symbol && previous?.stockId === stockId ? previous.snapshot : null,
                    error: 'Fundamental snapshot is unavailable. Please retry.',
                }));
            } else {
                setRead({ symbol, stockId, state: 'confirmed', snapshot, error: null });
            }
        } catch (e) {
            if (!isCurrent()) return;
            setRead(previous => ({
                symbol,
                stockId,
                state: 'unavailable',
                snapshot: previous?.symbol === symbol && previous?.stockId === stockId ? previous.snapshot : null,
                error: getApiErrorMessage(e, 'Failed to load fundamental insights'),
            }));
        } finally {
            if (isCurrent()) setRefreshingAi(false);
        }
    }, []);

    useEffect(() => {
        setRefreshingAi(false);
        if (!routeSymbol) {
            setResolvedStock(null);
            setRead(null);
            return undefined;
        }
        let cancelled = false;
        const symbol = routeSymbol;
        const routeEpoch = routeEpochRef.current;
        const generation = ++readGenerationRef.current;
        const isCurrent = () => !cancelled
            && routeEpochRef.current === routeEpoch
            && routeIdentityRef.current === symbol
            && mountedRef.current
            && readGenerationRef.current === generation;
        setRead(previous => ({
            symbol,
            stockId: previous?.symbol === symbol ? previous.stockId : null,
            state: 'resolving',
            snapshot: previous?.symbol === symbol ? previous.snapshot : null,
            error: null,
        }));
        (async () => {
            try {
                const response = await api.get('/stocks/search', {
                    params: { q: symbol, limit: 8 },
                    skipErrorToast: true,
                });
                if (!isCurrent()) return;
                const rows = response?.data?.data ?? response?.data ?? [];
                const match = resolveStockFromSearchRows(rows, symbol);
                if (!match?.id) {
                    setResolvedStock(previous => previous?.symbol === symbol ? null : previous);
                    setRead({ symbol, stockId: null, state: 'unavailable', snapshot: null, error: `No stock found for symbol ${symbol}` });
                    return;
                }
                setResolvedStock({ symbol, stock: match });
                await loadInsights(match.id, symbol, routeEpoch, generation);
            } catch (e) {
                if (isCurrent()) setRead(previous => ({
                    symbol,
                    stockId: previous?.symbol === symbol ? previous.stockId : null,
                    state: 'unavailable',
                    snapshot: previous?.symbol === symbol ? previous.snapshot : null,
                    error: getApiErrorMessage(e, 'Failed to resolve symbol'),
                }));
            }
        })();
        return () => {
            cancelled = true;
            if (readGenerationRef.current === generation) readGenerationRef.current += 1;
        };
    }, [routeSymbol, loadInsights, retryKey]);

    const onStockPick = (picked) => {
        if (!picked?.symbol) return;
        navigate(`${ROUTES.FUNDAMENTAL_INSIGHTS}/${picked.symbol}`);
    };

    const currentStock = resolvedStock?.symbol === routeSymbol ? resolvedStock.stock : null;
    const routeRead = read?.symbol === routeSymbol ? read : null;
    const currentRead = routeRead && (
        !currentStock || routeRead.stockId == null || routeRead.stockId === currentStock.id
    ) ? routeRead : null;
    const stock = currentStock;
    const snapshot = currentRead?.snapshot ?? null;
    const loading = Boolean(routeSymbol) && (!currentRead || ['resolving', 'loading'].includes(currentRead.state));
    const error = currentRead?.state === 'unavailable' ? currentRead.error : null;
    const stale = Boolean(snapshot && (loading || error));

    const onRefreshAi = async () => {
        if (!stock?.id || !routeSymbol) return;
        const generation = ++readGenerationRef.current;
        const routeEpoch = routeEpochRef.current;
        setRefreshingAi(true);
        await loadInsights(stock.id, routeSymbol, routeEpoch, generation);
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
                <div className="alert alert-warning mt-3 py-2 small" role="alert"><p>{error}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={() => setRetryKey(key => key + 1)}>Retry fundamental insights</button></div>
            ) : null}

            {loading && !snapshot ? (
                <div className="text-muted small mt-3" role="status">Loading insights…</div>
            ) : null}

            {stock && snapshot ? (
                <div className="card mt-3">
                    <div className="card-body">
                        {stale ? <p className="alert alert-warning small" role="status">Showing last confirmed fundamentals for {stock.symbol}; they may be out of date.</p> : null}
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
