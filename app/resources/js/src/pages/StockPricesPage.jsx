import React, { useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../context/AuthContext';
import { DataTableCard } from '../components/DataTable';
import PriceVolumeChart from '../components/charts/PriceVolumeChart';
import { showToast } from '../toast';
import { formatTableInteger, formatTableMoney2 } from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import PortfolioContext from '../context/PortfolioContext';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';

export default function StockPricesPage() {
    const { user } = useAuth();
    const isAdmin = Boolean(user?.is_admin);
    const { stockId } = useParams();
    const [range, setRange] = useState('all');
    const portfolioContext = useContext(PortfolioContext);
    const hasPortfolioProvider = portfolioContext !== null;
    const contextPortfolioId = portfolioContext?.activePortfolioId ?? null;
    const storedPortfolioId = getActivePortfolioId();
    const normalizedStoredPortfolioId = storedPortfolioId == null || storedPortfolioId === '' ? null : String(storedPortfolioId);
    const profileId = hasPortfolioProvider
        ? (contextPortfolioId != null && String(contextPortfolioId) === normalizedStoredPortfolioId ? String(contextPortfolioId) : null)
        : normalizedStoredPortfolioId;
    const queryKey = profileId == null ? null : JSON.stringify([String(profileId), String(stockId), range]);
    const [readState, setReadState] = useState({ key: null, status: 'loading', error: '' });
    const [syncing, setSyncing] = useState(false);
    const [confirmed, setConfirmed] = useState(null);
    const [lastSync, setLastSync] = useState(null);
    const generationRef = useRef(0);
    const mountedRef = useRef(false);
    const lastDispatchedKeyRef = useRef(null);
    const committedPortfolioRef = useRef({ provided: hasPortfolioProvider, id: contextPortfolioId });

    useLayoutEffect(() => {
        committedPortfolioRef.current = { provided: hasPortfolioProvider, id: contextPortfolioId };
    }, [hasPortfolioProvider, contextPortfolioId]);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            generationRef.current += 1;
            lastDispatchedKeyRef.current = null;
        };
    }, []);

    const load = useCallback(async () => {
        const storedId = getActivePortfolioId();
        const committed = committedPortfolioRef.current;
        const activeId = committed.provided
            ? (committed.id != null && String(committed.id) === String(storedId) ? String(committed.id) : null)
            : storedId;
        const generation = ++generationRef.current;
        if (activeId == null || activeId === '') {
            setReadState({ key: null, status: 'waiting', error: '' });
            return;
        }
        const key = JSON.stringify([String(activeId), String(stockId), range]);
        lastDispatchedKeyRef.current = key;
        setReadState({ key, status: 'loading', error: '' });
        try {
            const res = await api.get(`/stocks/${stockId}/prices`, { params: { range } });
            if (!res?.data || !Array.isArray(res.data.data)) {
                throw new Error('Invalid price history response');
            }
            const latest = committedPortfolioRef.current;
            const latestStoredId = getActivePortfolioId();
            const scopeStillCurrent = latestStoredId != null
                && String(latestStoredId) === String(activeId)
                && (!latest.provided || String(latest.id) === String(activeId));
            if (mountedRef.current && generationRef.current === generation && scopeStillCurrent) {
                setConfirmed({ key, payload: res.data });
                setReadState({ key, status: 'confirmed', error: '' });
            }
        } catch {
            const latest = committedPortfolioRef.current;
            const latestStoredId = getActivePortfolioId();
            const scopeStillCurrent = latestStoredId != null
                && String(latestStoredId) === String(activeId)
                && (!latest.provided || String(latest.id) === String(activeId));
            if (mountedRef.current && generationRef.current === generation && scopeStillCurrent) {
                setReadState({ key, status: 'error', error: 'Failed to load price history.' });
            }
        }
    }, [stockId, range]);

    useEffect(() => {
        if (profileId == null) {
            load();
            return;
        }
        const key = JSON.stringify([String(profileId), String(stockId), range]);
        if (lastDispatchedKeyRef.current !== key) load();
    }, [load, profileId, stockId, range]);
    usePortfolioChanged(load);

    const payload = queryKey && confirmed?.key === queryKey ? confirmed.payload : null;
    const currentReadState = queryKey && readState.key === queryKey
        ? readState
        : (queryKey ? { key: queryKey, status: 'loading', error: '' } : { key: null, status: 'waiting', error: '' });
    const loading = currentReadState.status === 'loading';
    const error = currentReadState.status === 'error' ? currentReadState.error : '';
    const stale = Boolean(payload && (error || loading || readState.key !== queryKey));

    const forceSync = async () => {
        setSyncing(true);
        setLastSync(null);
        try {
            const res = await api.post(`/sync/backfill/${stockId}`);
            setConfirmed({ key: queryKey, payload: res.data });
            setLastSync(res.data.sync ? { key: queryKey, value: res.data.sync } : null);
            const stored = res.data.stored_rows ?? res.data.price_count ?? 0;
            const provider = res.data.sync?.provider ? ` via ${res.data.sync.provider}` : '';
            showToast(`Stored ${stored} price rows${provider}`);
        } catch (err) {
            const syncErrors = err?.response?.data?.errors?.sync;
            const detail = Array.isArray(syncErrors) ? syncErrors[0] : null;
            if (detail && queryKey) {
                setReadState({ key: queryKey, status: 'error', error: detail });
            }
        } finally {
            setSyncing(false);
        }
    };

    const columns = useMemo(() => [
        {
            accessorKey: 'price_date',
            header: 'Date',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()),
        },
        {
            accessorKey: 'open_price',
            header: 'Open',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },
        {
            accessorKey: 'high_price',
            header: 'High',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },
        {
            accessorKey: 'low_price',
            header: 'Low',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },
        {
            accessorKey: 'close_price',
            header: 'Close',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },
        {
            accessorKey: 'volume',
            header: 'Volume',
            cell: ({ getValue }) => formatTableInteger(getValue()),
        },
        { accessorKey: 'data_source', header: 'Source' },
    ], []);

    const stock = payload?.stock;
    const rows = payload?.data || [];

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <Link to="/holdings" className="btn btn-sm btn-outline-secondary me-2">← Holdings</Link>
                    <span className="h5 m-0">
                        {loading && !stock ? 'Loading…' : `${stock?.symbol || 'Stock'} — Price History`}
                    </span>
                </div>
                {isAdmin ? (
                <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={forceSync}
                    disabled={syncing || loading || !queryKey}
                >
                    {syncing ? 'Syncing…' : 'Force sync historical prices'}
                </button>
                ) : null}
            </div>

            {error && (
                <div className="alert alert-danger small mb-0" role="alert"><p>{error}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={load}>Retry price history</button></div>
            )}
            {!error && !payload && !loading && !profileId && (
                <div className="alert alert-info small mb-0" role="status">Waiting for an active portfolio before loading this range.</div>
            )}
            {!error && !payload && loading && (
                <div className="text-muted small" role="status">Loading price history…</div>
            )}
            {stale && (
                <div className="alert alert-warning small mb-0" role="status">Showing the last confirmed history for this portfolio and range; it may be out of date.</div>
            )}

            {lastSync?.key === queryKey && lastSync.value && (
                <div className="alert alert-success small mb-0">
                    Last sync: {lastSync.value.stored_rows} rows stored from {lastSync.value.from_date} to {lastSync.value.to_date}
                    {lastSync.value.provider ? ` (${lastSync.value.provider})` : ''}
                </div>
            )}

            <div className="card">
                <div className="card-body">
                    <div className="row g-2 small">
                        <div className="col-md-3"><strong>All history from:</strong> {payload?.all_from_date || payload?.from_date || '—'}</div>
                        <div className="col-md-3">
                            <strong>Rows ({range === 'since_buy' ? 'Since Buy' : 'All'}):</strong> {payload ? (payload.price_count ?? '—') : '—'}
                        </div>
                        <div className="col-md-3">
                            <strong>Since Buy from:</strong> {payload?.since_buy_from_date || '—'}
                        </div>
                        <div className="col-md-3">
                            <strong>Status:</strong>{' '}
                            {!payload ? '—' : payload.has_price_history ? (
                                <span className="text-success">Fetched</span>
                            ) : (
                                <span className="text-warning">{isAdmin ? 'Not fetched — use Force sync' : 'Price history unavailable — ask an administrator to review source coverage'}</span>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="d-flex gap-2 align-items-center">
                <div className="btn-group btn-group-sm" role="group" aria-label="Stock history range">
                    <button type="button" className={`btn ${range === 'all' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setRange('all')}>All</button>
                    <button type="button" className={`btn ${range === 'since_buy' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setRange('since_buy')}>Since Buy</button>
                </div>
                <span className="text-muted small">All = all available instrument history; Since Buy = current position episode.</span>
            </div>

            {payload ? (
                <>
                    <PriceVolumeChart
                        rows={rows}
                        loading={loading}
                        emptyMessage={isAdmin ? 'No historical prices to chart yet. Sync or load OHLCV data first.' : 'No cached historical prices are available for this range.'}
                    />
                    <DataTableCard
                        title={`OHLCV (${range === 'since_buy' ? 'Since Buy' : 'All Available'})`}
                        columns={columns}
                        data={rows}
                        storageKey={`stock-prices-${stockId}`}
                        loading={loading}
                        striped
                        emptyMessage={isAdmin ? 'No historical prices yet. Click "Force sync historical prices" to fetch all available history.' : 'No cached historical prices are available for this range.'}
                    />
                </>
            ) : null}
        </div>
    );
}
