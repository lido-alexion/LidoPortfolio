import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import StockAutocomplete from '../components/StockAutocomplete';
import PriceVolumeChart from '../components/charts/PriceVolumeChart';
import { DataTableCard } from '../components/DataTable';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { showToast } from '../toast';
import { categoryClassName, categoryLabel } from '../utils/patternDetection';
import { formatInrWhole } from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';
import { stockExchangeLabel } from '../utils/exchangeDisplay';

function WatchlistStockPanel({
    stock,
    watchlistEntry,
    note,
    onNoteChange,
    onAdd,
    onRemove,
    onSaveNote,
    saving,
    prices,
    pricesLoading,
    priceMeta,
}) {
    if (!stock) {
        return (
            <div className="card">
                <div className="card-body text-muted small">
                    Search for a stock above or pick one from your watchlist to view price history.
                </div>
            </div>
        );
    }

    const isOnWatchlist = Boolean(watchlistEntry);
    const title = `${stock.symbol} — ${stock.name || 'Price history'}`;

    return (
        <div className="d-grid gap-3">
            <div className="card">
                <div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <div className="h5 mb-1">{stock.symbol}</div>
                            <div className="text-muted small">{stock.name}</div>
                            <div className="text-muted small">{stockExchangeLabel(stock)}</div>
                        </div>
                        {priceMeta?.latest_close != null ? (
                            <div className="text-end">
                                <div className="text-muted small">Latest close</div>
                                <div className="fw-semibold">{formatInrWhole(priceMeta.latest_close)}</div>
                                {priceMeta.latest_price_date ? (
                                    <div className="text-muted small">
                                        {formatTransactionDateDisplay(priceMeta.latest_price_date)}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                    </div>

                    <div className="mb-3">
                        <label className="form-label small text-muted mb-1" htmlFor="watchlist-note">
                            Note (optional)
                        </label>
                        <textarea
                            id="watchlist-note"
                            className="form-control form-control-sm"
                            rows={2}
                            maxLength={500}
                            value={note}
                            onChange={(e) => onNoteChange(e.target.value)}
                            placeholder="Why you are watching this stock…"
                            disabled={saving}
                        />
                    </div>

                    <div className="d-flex flex-wrap gap-2">
                        {isOnWatchlist ? (
                            <>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-primary"
                                    onClick={onSaveNote}
                                    disabled={saving}
                                >
                                    {saving ? 'Saving…' : 'Save note'}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-danger"
                                    onClick={onRemove}
                                    disabled={saving}
                                >
                                    {saving ? 'Removing…' : 'Remove from watchlist'}
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                className="btn btn-sm btn-primary"
                                onClick={onAdd}
                                disabled={saving}
                            >
                                {saving ? 'Adding…' : 'Add to watchlist'}
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {priceMeta && !priceMeta.has_price_history && !pricesLoading ? (
                <div className="alert alert-warning small mb-0">
                    No cached OHLCV for this symbol yet. Prices appear after universe sync or when
                    the stock is validated and backfilled.
                </div>
            ) : null}

            <PriceVolumeChart
                rows={prices}
                loading={pricesLoading}
                title={title}
                emptyMessage="No cached price history for this stock."
            />
        </div>
    );
}

export default function WatchlistPage() {
    const [watchlist, setWatchlist] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedStock, setSelectedStock] = useState(null);
    const [searchSymbol, setSearchSymbol] = useState('');
    const [note, setNote] = useState('');
    const [prices, setPrices] = useState([]);
    const [priceMeta, setPriceMeta] = useState(null);
    const [pricesLoading, setPricesLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [scanRows, setScanRows] = useState([]);
    const [scanning, setScanning] = useState(false);
    const [scanDone, setScanDone] = useState(false);

    const loadWatchlist = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/watchlist');
            setWatchlist(res.data.data || []);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadWatchlist(); }, [loadWatchlist]);
    usePortfolioChanged(loadWatchlist);

    const activeEntry = useMemo(
        () => watchlist.find((item) => item.stock_id === selectedStock?.id) ?? null,
        [watchlist, selectedStock],
    );

    const loadPrices = useCallback(async (stockId) => {
        if (!stockId) {
            setPrices([]);
            setPriceMeta(null);
            return;
        }

        setPricesLoading(true);
        try {
            const res = await api.get(`/stocks/${stockId}/market-prices`);
            setPrices(res.data.data || []);
            setPriceMeta({
                has_price_history: res.data.has_price_history,
                price_count: res.data.price_count,
                latest_close: res.data.latest_close,
                latest_price_date: res.data.latest_price_date,
                from_date: res.data.from_date,
                to_date: res.data.to_date,
            });
        } catch {
            setPrices([]);
            setPriceMeta(null);
            showToast('Failed to load price history.', 'danger');
        } finally {
            setPricesLoading(false);
        }
    }, []);

    useEffect(() => {
        if (selectedStock?.id) {
            loadPrices(selectedStock.id);
        }
    }, [selectedStock?.id, loadPrices]);

    useEffect(() => {
        setNote(activeEntry?.note || '');
    }, [activeEntry?.id, activeEntry?.note]);

    const selectStock = useCallback((stock) => {
        setSelectedStock(stock);
        setSearchSymbol(stock.symbol);
    }, []);

    const scanColumns = useMemo(() => [
        {
            id: 'symbol',
            header: 'Symbol',
            accessorKey: 'symbol',
            cell: ({ row }) => (
                <button
                    type="button"
                    className="btn btn-link btn-sm p-0 align-baseline"
                    onClick={() => selectStock({
                        id: row.original.stock_id,
                        symbol: row.original.symbol,
                        name: row.original.name,
                        exchange: row.original.exchange,
                    })}
                >
                    {row.original.symbol}
                </button>
            ),
        },
        {
            id: 'pattern_name',
            header: 'Pattern',
            accessorKey: 'pattern_name',
        },
        {
            id: 'category',
            header: 'Signal',
            accessorKey: 'category',
            cell: ({ getValue }) => (
                <span className={categoryClassName(getValue())}>
                    {categoryLabel(getValue())}
                </span>
            ),
        },
        {
            id: 'bar_date',
            header: 'As of',
            accessorKey: 'bar_date',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()) || '—',
        },
    ], [selectStock]);

    const runWatchlistScan = useCallback(async () => {
        setScanning(true);
        setScanDone(false);
        try {
            const res = await api.get('/patterns/scan', {
                params: { scope: 'watchlist', actionable_only: false },
            });
            const flat = [];
            for (const stock of res.data.results || []) {
                for (const match of stock.matches || []) {
                    flat.push({
                        stock_id: stock.stock_id,
                        symbol: stock.symbol,
                        name: stock.name,
                        exchange: stock.exchange,
                        pattern_name: match.name,
                        category: match.category,
                        bar_date: match.bar_date,
                    });
                }
            }
            setScanRows(flat);
            setScanDone(true);
            if (flat.length === 0) {
                showToast('Scan complete — no patterns matched on the latest bar.');
            } else {
                showToast(`Scan found ${flat.length} pattern match${flat.length === 1 ? '' : 'es'}.`);
            }
        } catch {
            showToast('Pattern scan failed.', 'danger');
            setScanRows([]);
        } finally {
            setScanning(false);
        }
    }, []);

    const handleSearchSelect = useCallback((stock) => {
        selectStock(stock);
    }, [selectStock]);

    const handleAdd = async () => {
        if (!selectedStock?.id) {
            return;
        }
        setSaving(true);
        try {
            const res = await api.post('/watchlist', {
                stock_id: selectedStock.id,
                note: note.trim() || null,
            });
            const item = res.data.data;
            setWatchlist((prev) => [item, ...prev.filter((row) => row.stock_id !== item.stock_id)]);
            showToast(`${selectedStock.symbol} added to watchlist.`);
        } catch (err) {
            const message = err?.response?.data?.message
                || err?.response?.data?.errors?.stock_id?.[0]
                || 'Could not add to watchlist.';
            showToast(message, 'danger');
        } finally {
            setSaving(false);
        }
    };

    const handleRemove = async () => {
        if (!activeEntry?.id) {
            return;
        }
        setSaving(true);
        try {
            await api.delete(`/watchlist/${activeEntry.id}`);
            setWatchlist((prev) => prev.filter((row) => row.id !== activeEntry.id));
            showToast(`${selectedStock?.symbol || 'Stock'} removed from watchlist.`);
        } catch {
            showToast('Could not remove from watchlist.', 'danger');
        } finally {
            setSaving(false);
        }
    };

    const handleSaveNote = async () => {
        if (!activeEntry?.id) {
            return;
        }
        setSaving(true);
        try {
            const res = await api.put(`/watchlist/${activeEntry.id}`, {
                note: note.trim() || null,
            });
            const item = res.data.data;
            setWatchlist((prev) => prev.map((row) => (row.id === item.id ? item : row)));
            showToast('Note saved.');
        } catch {
            showToast('Could not save note.', 'danger');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div className="mb-0">
                        Watchlist
                        {!loading ? (
                            <span className="lido-card-title-count">({watchlist.length})</span>
                        ) : null}
                    </div>
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        onClick={runWatchlistScan}
                        disabled={scanning || loading || watchlist.length === 0}
                        title="Run OHLCV pattern rules on cached prices for every watchlist symbol"
                    >
                        {scanning ? 'Scanning…' : 'Scan my watchlist'}
                    </button>
                </div>
                <div className="card-body">
                    <StockAutocomplete
                        id="watchlist-stock-search"
                        value={searchSymbol}
                        onChange={setSearchSymbol}
                        onSelect={handleSearchSelect}
                        hideLabel={false}
                        placeholder="Search NSE stocks in local database (min 2 chars)"
                    />
                </div>
            </div>

            <WatchlistStockPanel
                stock={selectedStock}
                watchlistEntry={activeEntry}
                note={note}
                onNoteChange={setNote}
                onAdd={handleAdd}
                onRemove={handleRemove}
                onSaveNote={handleSaveNote}
                saving={saving}
                prices={prices}
                pricesLoading={pricesLoading}
                priceMeta={priceMeta}
            />

            <div className="card">
                <div className="card-header">
                    <div className="mb-0">Your watchlist</div>
                </div>
                <div className="card-body p-0">
                    {loading ? (
                        <div className="text-muted small p-3">Loading watchlist…</div>
                    ) : watchlist.length === 0 ? (
                        <div className="text-muted small p-3">
                            No stocks on your watchlist yet. Search above and add one.
                        </div>
                    ) : (
                        <ul className="list-group list-group-flush">
                            {watchlist.map((item) => {
                                const isActive = selectedStock?.id === item.stock_id;
                                return (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            className={[
                                                'list-group-item list-group-item-action text-start',
                                                isActive ? 'active' : '',
                                            ].join(' ')}
                                            onClick={() => selectStock(item.stock)}
                                        >
                                            <div className="d-flex justify-content-between align-items-start gap-2">
                                                <div>
                                                    <strong>{item.stock?.symbol}</strong>
                                                    <span className="ms-2 small opacity-75">
                                                        {item.stock?.exchange}
                                                    </span>
                                                    {item.stock?.name ? (
                                                        <div className="small opacity-75">
                                                            {item.stock.name}
                                                        </div>
                                                    ) : null}
                                                    {item.note ? (
                                                        <div className="small mt-1 fst-italic opacity-75">
                                                            {item.note}
                                                        </div>
                                                    ) : null}
                                                </div>
                                                <div className="text-end small">
                                                    {item.latest_close != null ? (
                                                        <div>{formatInrWhole(item.latest_close)}</div>
                                                    ) : (
                                                        <div className="opacity-75">No price</div>
                                                    )}
                                                    {item.price_count > 0 ? (
                                                        <div className="opacity-75">
                                                            {item.price_count} rows
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>

            {scanDone ? (
                <DataTableCard
                    title="Watchlist pattern scan"
                    columns={scanColumns}
                    data={scanRows}
                    storageKey="watchlist-pattern-scan-v1"
                    emptyMessage="No patterns detected on the latest bar for any watchlist symbol."
                    headerExtra={(
                        <Link to="/patterns" className="btn btn-sm btn-outline-secondary">
                            Pattern guide
                        </Link>
                    )}
                />
            ) : null}
        </div>
    );
}
