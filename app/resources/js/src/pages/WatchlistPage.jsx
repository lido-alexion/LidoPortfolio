import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../api';
import AddToWatchlistComboButton from '../components/AddToWatchlistComboButton';
import AnalyseStockButton from '../components/AnalyseStockButton';
import CompareStrengthComboButton from '../components/CompareStrengthComboButton';
import ManageWatchlistsModal from '../components/ManageWatchlistsModal';
import PatternSketch from '../components/PatternSketch';
import StockAutocomplete from '../components/StockAutocomplete';
import PriceVolumeChart from '../components/charts/PriceVolumeChart';
import WatchlistResearchPanel from '../components/WatchlistResearchPanel';
import { IconDelete } from '../components/knowledgeBoard/KnowledgeCardIcons';
import { usePortfolio } from '../context/PortfolioContext';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { showToast } from '../toast';
import {
    clearActiveWatchlistId,
    loadActiveWatchlistId,
    saveActiveWatchlistId,
} from '../utils/activeWatchlistStorage';
import { categoryLabel } from '../utils/patternDetection';
import { patternGuideLink } from '../utils/patternGuideLinks';
import { stockExchangeLabel } from '../utils/exchangeDisplay';
import { formatInr, formatInrWhole } from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';

const SEARCH_PLACEHOLDER = 'Search for a stock here (min 2 characters) or pick one from your watchlist to view price history.';

function resolveStockFromSearchRows(rows, symbol) {
    const needle = String(symbol || '').toUpperCase();
    const exact = (rows || []).filter((row) => String(row.symbol || '').toUpperCase() === needle);
    if (exact.length === 0) {
        return null;
    }
    return exact.find((row) => row.exchange === 'NSE') || exact[0];
}
const SORT_OPTIONS = [
    { value: 'symbol', label: 'Symbol A–Z' },
    { value: '-symbol', label: 'Symbol Z–A' },
    { value: 'name', label: 'Name A–Z' },
    { value: '-latest_close', label: 'Price high–low' },
    { value: 'latest_close', label: 'Price low–high' },
    { value: '-daily_change_percent', label: 'Change % high–low' },
    { value: 'daily_change_percent', label: 'Change % low–high' },
    { value: '-updated_at', label: 'Recently updated' },
];

function formatSignedChange(change, percent) {
    if (change == null || percent == null) {
        return null;
    }
    const changeNum = Number(change);
    const percentNum = Number(percent);
    if (Number.isNaN(changeNum) || Number.isNaN(percentNum)) {
        return null;
    }
    const absChange = formatInr(Math.abs(changeNum)).replace(/^₹\s*/, '');
    const sign = changeNum > 0 ? '+' : changeNum < 0 ? '−' : '';
    const pctSign = percentNum > 0 ? '+' : percentNum < 0 ? '−' : '';
    return `${sign}₹ ${absChange} (${pctSign}${Math.abs(percentNum).toFixed(2)}%)`;
}

function matchCountFromResults(results) {
    return (results || []).reduce(
        (sum, stock) => sum + (stock.matches?.length || 0),
        0,
    );
}

function apiErrorMessage(error, fallback) {
    return error?.response?.data?.message
        || error?.response?.data?.errors?.stock_id?.[0]
        || error?.response?.data?.errors?.name?.[0]
        || fallback;
}

function BriefcaseIcon() {
    return (
        <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true" focusable="false">
            <path
                d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7m-11 4h16m-1 8H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2Z"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function HoldingInfo({ holding, symbol }) {
    if (!holding) {
        return null;
    }

    const unrealized = holding.unrealized_profit;
    const unrealizedClass = Number(unrealized) > 0
        ? 'text-success'
        : Number(unrealized) < 0
            ? 'text-danger'
            : '';

    return (
        <span
            className="lido-watchlist-holding"
            aria-label={`${symbol || 'Stock'} holding details`}
        >
            <BriefcaseIcon />
            <span className="lido-watchlist-holding-tooltip" role="tooltip">
                <strong className="d-block mb-1">In your holdings</strong>
                <span><span>Units held</span><strong>{Number(holding.quantity).toLocaleString('en-IN', { maximumFractionDigits: 4 })}</strong></span>
                <span><span>Avg. buy price</span><strong>{formatInr(holding.avg_buy_price)}</strong></span>
                <span><span>Total invested</span><strong>{formatInr(holding.invested_amount)}</strong></span>
                <span>
                    <span>Unrealized P/L</span>
                    <strong className={unrealizedClass}>
                        {unrealized == null ? '—' : formatInr(unrealized)}
                    </strong>
                </span>
            </span>
        </span>
    );
}

function StockPatternMatches({ scan, loading }) {
    if (loading) {
        return (
            <div className="small text-muted mt-3">Scanning for patterns…</div>
        );
    }
    if (!scan) {
        return null;
    }

    const matches = scan.matches || [];
    if (matches.length === 0) {
        return (
            <div className="small text-muted mt-3">
                No patterns matched on the latest bar.
            </div>
        );
    }

    return (
        <div className="mt-3" aria-label="Matched patterns">
            <div className="small fw-semibold mb-1">
                Matched patterns
                {scan.price_as_of ? (
                    <span className="text-muted fw-normal">
                        {' '}· as of {formatTransactionDateDisplay(scan.price_as_of)}
                    </span>
                ) : null}
            </div>
            <div className="d-flex flex-wrap gap-2">
                {matches.map((match) => (
                    <Link
                        key={`${match.id}-${match.bar_date || ''}`}
                        to={patternGuideLink(match.id)}
                        className="badge bg-light text-dark border text-decoration-none d-inline-flex align-items-center gap-1"
                        title={[
                            match.name || match.id,
                            categoryLabel(match.category),
                            match.bar_date
                                ? `As of ${formatTransactionDateDisplay(match.bar_date)}`
                                : null,
                        ].filter(Boolean).join(' · ')}
                    >
                        <PatternSketch
                            patternId={match.id}
                            className="lido-pattern-sketch--watchlist"
                            title=""
                        />
                        <span>{match.name || match.id}</span>
                        <span className="text-muted">({categoryLabel(match.category)})</span>
                    </Link>
                ))}
            </div>
        </div>
    );
}

function WatchlistStockPanel({
    stock,
    activeWatchlist,
    activeEntry,
    membershipIds,
    watchlists,
    benchmarkIndexes,
    note,
    onNoteChange,
    onAdd,
    onRemove,
    onSaveNote,
    saving,
    prices,
    pricesLoading,
    priceMeta,
    patternScan,
    patternScanLoading,
}) {
    if (!stock) {
        return null;
    }

    const isOnActiveWatchlist = Boolean(activeEntry);
    const title = `${stock.symbol} — ${stock.name || 'Price history'}`;
    const otherMembershipCount = membershipIds.filter((id) => id !== activeWatchlist?.id).length;

    return (
        <div className="d-grid gap-3">
            <div className="card">
                <div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <div className="h5 mb-1 lido-stock-symbol-with-analyse">
                                <span>{stock.symbol}</span>
                                <AnalyseStockButton
                                    stockId={stock.id}
                                    symbol={stock.symbol}
                                    name={stock.name}
                                    size={16}
                                />
                            </div>
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

                    {isOnActiveWatchlist ? (
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
                                onChange={(event) => onNoteChange(event.target.value)}
                                placeholder="Why you are watching this stock…"
                                disabled={saving}
                            />
                        </div>
                    ) : null}

                    <div className="d-flex flex-wrap gap-2 align-items-center">
                        {isOnActiveWatchlist ? (
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
                                    {saving ? 'Removing…' : `Remove from ${activeWatchlist?.name || 'watchlist'}`}
                                </button>
                                <CompareStrengthComboButton
                                    stockSymbol={stock.symbol}
                                    indexes={benchmarkIndexes}
                                />
                            </>
                        ) : (
                            <>
                                <AddToWatchlistComboButton
                                    watchlists={watchlists}
                                    activeWatchlistId={activeWatchlist?.id}
                                    membershipIds={membershipIds}
                                    onAdd={onAdd}
                                    saving={saving}
                                />
                                <CompareStrengthComboButton
                                    stockSymbol={stock.symbol}
                                    indexes={benchmarkIndexes}
                                />
                            </>
                        )}
                        {!isOnActiveWatchlist && otherMembershipCount > 0 ? (
                            <span className="small text-muted">
                                On {otherMembershipCount} other watchlist{otherMembershipCount === 1 ? '' : 's'}
                            </span>
                        ) : null}
                    </div>

                    <StockPatternMatches scan={patternScan} loading={patternScanLoading} />
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

            <WatchlistResearchPanel stockId={stock.id} />
        </div>
    );
}

export default function WatchlistPage() {
    const { activePortfolio } = usePortfolio();
    const profileId = activePortfolio?.id ?? null;
    const navigate = useNavigate();
    const { symbol: symbolParam } = useParams();
    const symbolFromUrl = symbolParam ? decodeURIComponent(symbolParam).trim().toUpperCase() : '';

    const [watchlists, setWatchlists] = useState([]);
    const [activeWatchlistId, setActiveWatchlistId] = useState(null);
    const [items, setItems] = useState([]);
    const [loadingLists, setLoadingLists] = useState(true);
    const [loadingItems, setLoadingItems] = useState(false);
    const [selectedStock, setSelectedStock] = useState(null);
    const [searchSymbol, setSearchSymbol] = useState('');
    const [note, setNote] = useState('');
    const [membershipIds, setMembershipIds] = useState([]);
    const [prices, setPrices] = useState([]);
    const [priceMeta, setPriceMeta] = useState(null);
    const [pricesLoading, setPricesLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [scanning, setScanning] = useState(false);
    const [manageOpen, setManageOpen] = useState(false);
    const [itemSearch, setItemSearch] = useState('');
    const [itemSort, setItemSort] = useState('symbol');
    const [quickAddSymbol, setQuickAddSymbol] = useState('');
    const [quickAddKey, setQuickAddKey] = useState(0);
    const [removingItemId, setRemovingItemId] = useState(null);
    const [benchmarkIndexes, setBenchmarkIndexes] = useState([]);
    const [patternScan, setPatternScan] = useState(null);
    const [patternScanLoading, setPatternScanLoading] = useState(false);

    const activeWatchlist = useMemo(
        () => watchlists.find((row) => row.id === activeWatchlistId) ?? null,
        [watchlists, activeWatchlistId],
    );

    const activeEntry = useMemo(
        () => items.find((item) => item.stock_id === selectedStock?.id) ?? null,
        [items, selectedStock],
    );

    const loadWatchlists = useCallback(async () => {
        setLoadingLists(true);
        try {
            const res = await api.get('/watchlists');
            const rows = res.data.data || [];
            setWatchlists(rows);

            const storedId = loadActiveWatchlistId(profileId);
            const validStored = rows.some((row) => row.id === storedId);
            const nextId = validStored ? storedId : rows[0]?.id ?? null;
            setActiveWatchlistId(nextId);
            if (nextId && profileId) {
                saveActiveWatchlistId(profileId, nextId);
            }

            return rows;
        } finally {
            setLoadingLists(false);
        }
    }, [profileId]);

    useEffect(() => {
        let active = true;
        api.get('/indexes', { skipErrorToast: true })
            .then((res) => {
                if (!active) {
                    return;
                }
                const list = res.data?.data?.indexes ?? [];
                if (list.length > 0) {
                    setBenchmarkIndexes(list);
                }
            })
            .catch(() => {
                setBenchmarkIndexes([
                    { symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', is_primary: true },
                ]);
            });
        return () => {
            active = false;
        };
    }, []);

    const loadItems = useCallback(async (watchlistId, search, sort) => {
        if (!watchlistId) {
            setItems([]);
            return;
        }

        setLoadingItems(true);
        try {
            const res = await api.get(`/watchlists/${watchlistId}/items`, {
                params: {
                    search: search.trim() || undefined,
                    sort,
                },
            });
            setItems(res.data.data || []);
        } catch {
            setItems([]);
            showToast('Failed to load watchlist items.', 'danger');
        } finally {
            setLoadingItems(false);
        }
    }, []);

    const loadMembership = useCallback(async (stockId) => {
        if (!stockId) {
            setMembershipIds([]);
            return;
        }

        try {
            const res = await api.get('/watchlist/membership', {
                params: { stock_id: stockId },
            });
            setMembershipIds(res.data.watchlist_ids || []);
        } catch {
            setMembershipIds([]);
        }
    }, []);

    useEffect(() => {
        loadWatchlists();
    }, [loadWatchlists]);

    const handlePortfolioChanged = useCallback(() => {
        clearActiveWatchlistId(profileId);
        setActiveWatchlistId(null);
        setSelectedStock(null);
        setSearchSymbol('');
        navigate('/watchlist', { replace: true });
        loadWatchlists();
    }, [profileId, navigate, loadWatchlists]);

    usePortfolioChanged(handlePortfolioChanged);
    useEffect(() => {
        if (activeWatchlistId) {
            loadItems(activeWatchlistId, itemSearch, itemSort);
        }
    }, [activeWatchlistId, itemSearch, itemSort, loadItems]);

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

    const loadStockPatterns = useCallback(async (stockId) => {
        if (!stockId) {
            setPatternScan(null);
            return;
        }

        setPatternScanLoading(true);
        try {
            const res = await api.get(`/stocks/${stockId}/pattern-scan`, {
                skipErrorToast: true,
            });
            setPatternScan(res.data || null);

            // A fresh scan is written back to member watchlists server-side;
            // reflect it on the visible list rows too.
            if (res.data?.persisted) {
                const matches = res.data.matches || [];
                setItems((prev) => prev.map((item) => (
                    item.stock_id === stockId
                        ? { ...item, pattern_matches: matches }
                        : item
                )));
            }
        } catch {
            setPatternScan(null);
        } finally {
            setPatternScanLoading(false);
        }
    }, []);

    useEffect(() => {
        if (selectedStock?.id) {
            loadPrices(selectedStock.id);
            loadMembership(selectedStock.id);
            loadStockPatterns(selectedStock.id);
        } else {
            setPrices([]);
            setPriceMeta(null);
            setMembershipIds([]);
            setPatternScan(null);
        }
    }, [selectedStock?.id, loadPrices, loadMembership, loadStockPatterns]);

    useEffect(() => {
        setNote(activeEntry?.note || '');
    }, [activeEntry?.id, activeEntry?.note]);

    const selectStock = useCallback((stock) => {
        if (!stock?.symbol) {
            return;
        }
        const sym = String(stock.symbol).toUpperCase();
        setSelectedStock(stock);
        setSearchSymbol(sym);
        if (symbolFromUrl !== sym) {
            navigate(`/watchlist/${encodeURIComponent(sym)}`);
        }
    }, [navigate, symbolFromUrl]);

    useEffect(() => {
        if (!symbolFromUrl) {
            setSelectedStock((prev) => (prev ? null : prev));
            setSearchSymbol((prev) => (prev ? '' : prev));
            return undefined;
        }

        if (String(selectedStock?.symbol || '').toUpperCase() === symbolFromUrl) {
            return undefined;
        }

        const fromItems = items.find(
            (item) => String(item.stock?.symbol || '').toUpperCase() === symbolFromUrl,
        )?.stock;
        if (fromItems) {
            setSelectedStock(fromItems);
            setSearchSymbol(fromItems.symbol);
            return undefined;
        }

        let cancelled = false;
        (async () => {
            if (symbolFromUrl.length < 2) {
                if (!cancelled) {
                    showToast(`Stock ${symbolFromUrl} not found.`, 'danger');
                    navigate('/watchlist', { replace: true });
                }
                return;
            }

            try {
                const res = await api.get('/stocks/search', {
                    params: { q: symbolFromUrl, limit: 20 },
                    skipErrorToast: true,
                });
                if (cancelled) {
                    return;
                }
                const stock = resolveStockFromSearchRows(res.data?.data || [], symbolFromUrl);
                if (stock) {
                    setSelectedStock(stock);
                    setSearchSymbol(stock.symbol);
                } else {
                    showToast(`Stock ${symbolFromUrl} not found.`, 'danger');
                    navigate('/watchlist', { replace: true });
                }
            } catch {
                if (!cancelled) {
                    showToast(`Could not load stock ${symbolFromUrl}.`, 'danger');
                    navigate('/watchlist', { replace: true });
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [symbolFromUrl, items, selectedStock?.symbol, navigate]);

    const handleWatchlistChange = useCallback((watchlistId) => {
        setActiveWatchlistId(watchlistId);
        if (profileId) {
            saveActiveWatchlistId(profileId, watchlistId);
        }
    }, [profileId]);

    const handleManageChanged = useCallback(async ({ deletedId } = {}) => {
        const rows = await loadWatchlists();
        if (deletedId && deletedId === activeWatchlistId) {
            const nextId = rows[0]?.id ?? null;
            setActiveWatchlistId(nextId);
            if (nextId && profileId) {
                saveActiveWatchlistId(profileId, nextId);
            }
        }
    }, [loadWatchlists, activeWatchlistId, profileId]);

    const runWatchlistScan = useCallback(async () => {
        if (!activeWatchlistId) {
            return;
        }

        setScanning(true);
        try {
            const res = await api.get('/patterns/scan', {
                params: {
                    scope: 'watchlist',
                    watchlist_id: activeWatchlistId,
                    actionable_only: false,
                },
            });

            const matchesByStock = {};
            for (const stock of res.data.results || []) {
                matchesByStock[stock.stock_id] = stock.matches || [];
            }

            // Apply immediately from scan payload, then refresh from persisted storage.
            setItems((prev) => prev.map((item) => ({
                ...item,
                pattern_matches: matchesByStock[item.stock_id] || [],
            })));

            await loadItems(activeWatchlistId, itemSearch, itemSort);

            // If persistence/read-back omitted matches, keep the scan payload visible.
            if (matchCountFromResults(res.data.results) > 0) {
                setItems((prev) => {
                    const hasPersisted = prev.some((item) => (item.pattern_matches || []).length > 0);
                    if (hasPersisted) {
                        return prev;
                    }
                    return prev.map((item) => ({
                        ...item,
                        pattern_matches: matchesByStock[item.stock_id] || [],
                    }));
                });
            }

            const matchCount = matchCountFromResults(res.data.results);
            if (matchCount === 0) {
                showToast('Scan complete — no patterns matched on the latest bar.');
            } else {
                showToast(`Scan found ${matchCount} pattern match${matchCount === 1 ? '' : 'es'}.`);
            }
        } catch {
            showToast('Pattern scan failed.', 'danger');
        } finally {
            setScanning(false);
        }
    }, [activeWatchlistId, itemSearch, itemSort, loadItems]);

    const handleSearchSelect = useCallback((stock) => {
        selectStock(stock);
    }, [selectStock]);

    const handleAdd = async (watchlistId, stockOverride = null) => {
        const stock = stockOverride || selectedStock;
        if (!stock?.id || !watchlistId) {
            return;
        }

        setSaving(true);
        try {
            const res = await api.post(`/watchlists/${watchlistId}/items`, {
                stock_id: stock.id,
                note: stockOverride ? null : (note.trim() || null),
            });
            const item = res.data.data;
            if (selectedStock?.id === stock.id) {
                await loadMembership(stock.id);
                // Persist the scan to the newly joined watchlist so row icons show.
                loadStockPatterns(stock.id);
            }
            if (watchlistId === activeWatchlistId) {
                setItems((prev) => [item, ...prev.filter((row) => row.stock_id !== item.stock_id)]);
            }
            setWatchlists((prev) => prev.map((row) => (
                row.id === watchlistId
                    ? { ...row, item_count: (row.item_count || 0) + 1 }
                    : row
            )));
            const targetName = watchlists.find((row) => row.id === watchlistId)?.name || 'watchlist';
            showToast(`${stock.symbol} added to ${targetName}.`);
            return true;
        } catch (err) {
            showToast(apiErrorMessage(err, 'Could not add to watchlist.'), 'danger');
            return false;
        } finally {
            setSaving(false);
        }
    };

    const handleQuickAdd = async (stock) => {
        if (!activeWatchlistId || !stock?.id) {
            return;
        }

        const added = await handleAdd(activeWatchlistId, stock);
        if (added) {
            setQuickAddSymbol('');
            setQuickAddKey((key) => key + 1);
            selectStock(stock);
        }
    };

    const handleRemove = async () => {
        if (!activeEntry?.id) {
            return;
        }

        await handleRemoveItem(activeEntry);
    };

    const handleRemoveItem = async (item) => {
        if (!item?.id) {
            return;
        }

        setRemovingItemId(item.id);
        setSaving(true);
        try {
            await api.delete(`/watchlist-items/${item.id}`);
            setItems((prev) => prev.filter((row) => row.id !== item.id));
            if (selectedStock?.id === item.stock_id) {
                setMembershipIds((prev) => prev.filter((id) => id !== activeWatchlistId));
            }
            setWatchlists((prev) => prev.map((row) => (
                row.id === activeWatchlistId
                    ? { ...row, item_count: Math.max(0, (row.item_count || 0) - 1) }
                    : row
            )));
            showToast(`${item.stock?.symbol || 'Stock'} removed from ${activeWatchlist?.name || 'watchlist'}.`);
        } catch {
            showToast('Could not remove from watchlist.', 'danger');
        } finally {
            setRemovingItemId(null);
            setSaving(false);
        }
    };

    const handleSaveNote = async () => {
        if (!activeEntry?.id) {
            return;
        }

        setSaving(true);
        try {
            const res = await api.put(`/watchlist-items/${activeEntry.id}`, {
                note: note.trim() || null,
            });
            const item = res.data.data;
            setItems((prev) => prev.map((row) => (row.id === item.id ? item : row)));
            showToast('Note saved.');
        } catch {
            showToast('Could not save note.', 'danger');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="d-grid gap-3">
            <div>
                <p className="text-muted small mb-0">
                    Should I invest in this stock? Select a symbol for Stock Analytics, Evaluation Profile, and Recommendation Preview.
                </p>
            </div>
            <StockAutocomplete
                id="watchlist-stock-search"
                value={searchSymbol}
                onChange={setSearchSymbol}
                onSelect={handleSearchSelect}
                exchange={null}
                hideLabel={false}
                placeholder={SEARCH_PLACEHOLDER}
            />

            <WatchlistStockPanel
                stock={selectedStock}
                activeWatchlist={activeWatchlist}
                activeEntry={activeEntry}
                membershipIds={membershipIds}
                watchlists={watchlists}
                benchmarkIndexes={benchmarkIndexes}
                note={note}
                onNoteChange={setNote}
                onAdd={handleAdd}
                onRemove={handleRemove}
                onSaveNote={handleSaveNote}
                saving={saving}
                prices={prices}
                pricesLoading={pricesLoading}
                priceMeta={priceMeta}
                patternScan={patternScan}
                patternScanLoading={patternScanLoading}
            />

            <div className="card">
                <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div className="d-flex flex-wrap gap-2 align-items-center min-w-0">
                        {loadingLists ? (
                            <div className="text-muted small mb-0">Loading watchlists…</div>
                        ) : (
                            <>
                                <label className="visually-hidden" htmlFor="active-watchlist-select">
                                    Active watchlist
                                </label>
                                <select
                                    id="active-watchlist-select"
                                    className="form-select form-select-sm lido-watchlist-active-select"
                                    value={activeWatchlistId ?? ''}
                                    onChange={(event) => handleWatchlistChange(Number.parseInt(event.target.value, 10))}
                                    disabled={watchlists.length === 0}
                                >
                                    {watchlists.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name} ({row.item_count})
                                        </option>
                                    ))}
                                </select>
                            </>
                        )}
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            onClick={() => setManageOpen(true)}
                            disabled={loadingLists}
                        >
                            Manage
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-primary"
                            onClick={runWatchlistScan}
                            disabled={scanning || loadingLists || loadingItems || !activeWatchlistId || items.length === 0}
                            title="Run OHLCV pattern rules on cached prices for the active watchlist"
                        >
                            {scanning ? 'Scanning…' : 'Scan watchlist'}
                        </button>
                    </div>
                </div>
                <div className="card-body border-bottom py-2">
                    <div className="d-flex flex-wrap gap-2 align-items-center lido-watchlist-toolbar">
                        <div className="lido-watchlist-quick-add">
                            <StockAutocomplete
                                key={quickAddKey}
                                id="watchlist-quick-add"
                                value={quickAddSymbol}
                                onChange={setQuickAddSymbol}
                                onSelect={handleQuickAdd}
                                exchange={null}
                                hideLabel
                                clearOnBlur
                                disabled={!activeWatchlistId || saving}
                                placeholder="Search & add stock…"
                            />
                        </div>
                        <input
                            type="search"
                            className="form-control form-control-sm lido-watchlist-filter"
                            placeholder="Filter symbol, name, note"
                            value={itemSearch}
                            onChange={(event) => setItemSearch(event.target.value)}
                            disabled={!activeWatchlistId}
                            aria-label="Filter watchlist items"
                        />
                        <select
                            className="form-select form-select-sm lido-watchlist-sort"
                            value={itemSort}
                            onChange={(event) => setItemSort(event.target.value)}
                            disabled={!activeWatchlistId}
                            aria-label="Sort watchlist items"
                        >
                            {SORT_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
                <div className="card-body p-0">
                    {loadingItems ? (
                        <div className="text-muted small p-3">Loading items…</div>
                    ) : items.length === 0 ? (
                        <div className="text-muted small p-3">
                            {itemSearch.trim()
                                ? 'No stocks match your filter.'
                                : 'No stocks on this watchlist yet. Use Search & add above, or search at the top of the page.'}
                        </div>
                    ) : (
                        <ul className="list-group list-group-flush">
                            {items.map((item) => {
                                const isActive = selectedStock?.id === item.stock_id;
                                const isRemoving = removingItemId === item.id;

                                return (
                                    <li
                                        key={item.id}
                                        className={[
                                            'list-group-item lido-watchlist-row',
                                            isActive ? 'active' : '',
                                        ].join(' ')}
                                    >
                                        <AnalyseStockButton
                                            className="lido-watchlist-analyse"
                                            stockId={item.stock?.id || item.stock_id}
                                            symbol={item.stock?.symbol}
                                            name={item.stock?.name}
                                        />
                                        <button
                                            type="button"
                                            className="lido-watchlist-row-main"
                                            onClick={() => selectStock(item.stock)}
                                        >
                                            <div className="d-flex justify-content-between align-items-start gap-2">
                                                <div>
                                                    <strong>{item.stock?.symbol}</strong>
                                                    <span className="ms-2 small opacity-75">
                                                        {item.stock?.exchange}
                                                    </span>
                                                    <HoldingInfo
                                                        holding={item.holding}
                                                        symbol={item.stock?.symbol}
                                                    />
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
                                                    {(() => {
                                                        const changeLabel = formatSignedChange(
                                                            item.daily_change,
                                                            item.daily_change_percent,
                                                        );
                                                        if (!changeLabel) {
                                                            return null;
                                                        }
                                                        const changeNum = Number(item.daily_change);
                                                        const toneClass = changeNum > 0
                                                            ? 'lido-watchlist-change--up'
                                                            : changeNum < 0
                                                                ? 'lido-watchlist-change--down'
                                                                : 'lido-watchlist-change--flat';
                                                        const freshnessClass = item.is_price_fresh
                                                            ? 'lido-watchlist-change--fresh'
                                                            : 'lido-watchlist-change--stale';
                                                        return (
                                                            <div
                                                                className={`lido-watchlist-change ${toneClass} ${freshnessClass}`}
                                                                title={item.is_price_fresh
                                                                    ? `As of ${formatTransactionDateDisplay(item.latest_price_date) || 'today'} (fresh)`
                                                                    : `As of ${formatTransactionDateDisplay(item.latest_price_date) || 'prior session'} (stale)`}
                                                            >
                                                                {changeLabel}
                                                            </div>
                                                        );
                                                    })()}
                                                </div>
                                            </div>
                                        </button>
                                        {(item.pattern_matches || []).length > 0 ? (
                                            <div className="lido-watchlist-row-patterns" aria-label="Matched patterns">
                                                {(item.pattern_matches || []).map((match) => {
                                                    const tip = [
                                                        match.name || match.id,
                                                        categoryLabel(match.category),
                                                        match.bar_date
                                                            ? `As of ${formatTransactionDateDisplay(match.bar_date)}`
                                                            : null,
                                                    ].filter(Boolean).join(' · ');

                                                    return (
                                                        <Link
                                                            key={`${item.id}-${match.id}-${match.bar_date || ''}`}
                                                            to={patternGuideLink(match.id)}
                                                            className="lido-watchlist-pattern-link"
                                                            title={tip}
                                                            aria-label={`${match.name || match.id}, ${categoryLabel(match.category)}`}
                                                            onClick={(event) => event.stopPropagation()}
                                                        >
                                                            <PatternSketch
                                                                patternId={match.id}
                                                                className="lido-pattern-sketch--watchlist"
                                                                title=""
                                                            />
                                                        </Link>
                                                    );
                                                })}
                                            </div>
                                        ) : null}
                                        <button
                                            type="button"
                                            className="btn btn-sm lido-watchlist-row-remove"
                                            title={`Remove ${item.stock?.symbol || 'stock'} from watchlist`}
                                            aria-label={`Remove ${item.stock?.symbol || 'stock'} from watchlist`}
                                            disabled={saving || isRemoving}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                handleRemoveItem(item);
                                            }}
                                        >
                                            <IconDelete size={18} />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>

            <ManageWatchlistsModal
                show={manageOpen}
                watchlists={watchlists}
                activeWatchlistId={activeWatchlistId}
                onClose={() => setManageOpen(false)}
                onChanged={handleManageChanged}
            />
        </div>
    );
}
