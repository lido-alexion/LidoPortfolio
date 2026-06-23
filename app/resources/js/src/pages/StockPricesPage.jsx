import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api';
import { DataTableCard } from '../components/DataTable';
import { showToast } from '../toast';
import { formatTableInteger, formatTableMoney2 } from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';

export default function StockPricesPage() {
    const { stockId } = useParams();
    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState(false);
    const [payload, setPayload] = useState(null);
    const [error, setError] = useState('');
    const [lastSync, setLastSync] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const res = await api.get(`/stocks/${stockId}/prices`);
            setPayload(res.data);
        } catch {
            setError('Failed to load price history.');
        } finally {
            setLoading(false);
        }
    }, [stockId]);

    useEffect(() => { load(); }, [load]);

    const forceSync = async () => {
        setSyncing(true);
        setLastSync(null);
        try {
            const res = await api.post(`/sync/backfill/${stockId}`);
            setPayload(res.data);
            setLastSync(res.data.sync || null);
            const stored = res.data.stored_rows ?? res.data.price_count ?? 0;
            const provider = res.data.sync?.provider ? ` via ${res.data.sync.provider}` : '';
            showToast(`Stored ${stored} price rows${provider}`);
        } catch (err) {
            const syncErrors = err?.response?.data?.errors?.sync;
            const detail = Array.isArray(syncErrors) ? syncErrors[0] : null;
            if (detail) {
                setError(detail);
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

    if (error && !payload && !loading) {
        return <div className="alert alert-danger">{error}</div>;
    }

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
                <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={forceSync}
                    disabled={syncing || loading}
                >
                    {syncing ? 'Syncing…' : 'Force sync historical prices'}
                </button>
            </div>

            {error && (
                <div className="alert alert-danger small mb-0">{error}</div>
            )}

            {lastSync && (
                <div className="alert alert-success small mb-0">
                    Last sync: {lastSync.stored_rows} rows stored from {lastSync.from_date} to {lastSync.to_date}
                    {lastSync.provider ? ` (${lastSync.provider})` : ''}
                </div>
            )}

            <div className="card">
                <div className="card-body">
                    <div className="row g-2 small">
                        <div className="col-md-3"><strong>From buy date:</strong> {payload?.from_date || '—'}</div>
                        <div className="col-md-3">
                            <strong>Rows:</strong> {payload?.price_count ?? 0}
                        </div>
                        <div className="col-md-3">
                            <strong>Status:</strong>{' '}
                            {payload?.has_price_history ? (
                                <span className="text-success">Fetched</span>
                            ) : (
                                <span className="text-warning">Not fetched — use Force sync</span>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <DataTableCard
                title="OHLCV (since buy date)"
                columns={columns}
                data={rows}
                storageKey={`stock-prices-${stockId}`}
                loading={loading}
                striped
                emptyMessage='No historical prices yet. Click "Force sync historical prices" to fetch from buy date.'
            />
        </div>
    );
}
