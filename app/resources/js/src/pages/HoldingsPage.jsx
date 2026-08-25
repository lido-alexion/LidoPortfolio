import React, { useCallback, useEffect, useMemo, useState } from 'react';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import ComboButton from '../components/ComboButton';
import AnalyseStockButton from '../components/AnalyseStockButton';
import { DataTableColumnMenu, DataTableView, useDataTableController } from '../components/DataTable';
import SegmentToggle from '../components/SegmentToggle';
import { formatTransactionDateDisplay } from '../utils/transactionDate';
import { buildSellPrefillFromHolding } from '../utils/sellTransactionPrefill';
import {
    formatInrWhole,
    formatLtpDrawdownLabel,
    formatSignedPercent2,
    formatSignedTableMoney2,
    formatTableInteger,
    formatTableMoney2,
    ltpDrawdownColorClass,
    ltpDrawdownFromHighPercent,
    percentChangeColorClass,
} from '../utils/tableFormat';

const HOLDINGS_VIEW_KEY = 'portfolio_holdings_view';

const HOLDINGS_VIEW_OPTIONS = [
    { value: 'simple', label: 'Simple' },
    { value: 'complex', label: 'Complex' },
];

const HOLDINGS_COLUMN_ORDER = [
    'stock',
    'stock_name',
    'latest_close',
    'unrealized_profit',
    'invested_amount',
    'target_amount',
    'fees',
    'xirr',
    'highest_close',
    'quantity',
    'avg_buy_price',
    'trailing_stop',
    'realized_profit',
    'sell',
];

const HOLDINGS_DEFAULT_COLUMN_VISIBILITY = {
    stock_name: false,
    fees: false,
    realized_profit: false,
    target_amount: false,
};

function loadHoldingsViewMode() {
    try {
        return localStorage.getItem(HOLDINGS_VIEW_KEY) === 'simple' ? 'simple' : 'complex';
    } catch {
        return 'complex';
    }
}

function saveHoldingsViewMode(mode) {
    try {
        localStorage.setItem(HOLDINGS_VIEW_KEY, mode);
    } catch {
        // Quota or private mode — ignore.
    }
}

function isBelowTrailingStop(summary) {
    const latestCloseNum = Number(summary?.latest_close);
    const trailingStopNum = Number(summary?.trailing_stop_price);
    return !Number.isNaN(latestCloseNum)
        && !Number.isNaN(trailingStopNum)
        && latestCloseNum < trailingStopNum;
}

function feesPercentOfInvested(fees, invested) {
    const feeNum = Number(fees);
    const investedNum = Number(invested);
    if (Number.isNaN(feeNum) || Number.isNaN(investedNum) || investedNum <= 0) {
        return null;
    }
    return Math.round((feeNum / investedNum) * 1000) / 10;
}

function InvestedTransactionsIcon() {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
            <path
                d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
            />
        </svg>
    );
}

function buildHoldingsColumns(complex, handleSell, handleCorporateAction, handleAdopt) {
    return [
        {
            id: 'stock',
            accessorKey: 'stock.symbol',
            header: 'Stock',
            cell: ({ row }) => {
                const s = row.original.summary;
                const since = formatTransactionDateDisplay(s.first_buy_date);
                const belowTrailingStop = isBelowTrailingStop(s);
                const stockName = row.original.stock?.name || '';
                return (
                    <>
                        <span className="lido-stock-symbol-with-analyse">
                            <Link
                                className="lido-table-link"
                                to={`/holdings/${row.original.stock_id}/prices`}
                                title={stockName || undefined}
                            >
                                <strong className={belowTrailingStop ? 'text-danger' : undefined}>
                                    {row.original.stock?.symbol}
                                </strong>
                            </Link>
                            <AnalyseStockButton
                                stockId={row.original.stock_id || row.original.stock?.id}
                                symbol={row.original.stock?.symbol}
                                name={stockName}
                            />
                        </span>
                        {row.original.is_unmanaged ? (
                            <div className="text-muted small">Unmanaged</div>
                        ) : row.original.owner_key ? (
                            <div className="text-muted small">{row.original.owner_key}</div>
                        ) : null}
                        {complex && since && (
                            <div className="text-muted small">Since {since}</div>
                        )}
                    </>
                );
            },
        },
        {
            id: 'stock_name',
            header: 'Name',
            accessorFn: (row) => row.stock?.name || '',
            cell: ({ getValue }) => {
                const name = getValue();
                if (!name) {
                    return <span className="text-muted">—</span>;
                }
                return <span className="small" title={name}>{name}</span>;
            },
        },
        {
            accessorKey: 'quantity',
            header: 'Qty',
            cell: ({ getValue }) => formatTableInteger(getValue()),
        },
        {
            accessorKey: 'avg_buy_price',
            header: 'Avg Buy',
            cell: ({ row, getValue }) => {
                const avg = formatTableMoney2(getValue());
                const s = row.original.summary || {};
                const fillCost = s.weighted_average_fill_cost != null
                    ? formatTableMoney2(s.weighted_average_fill_cost)
                    : null;
                const stopPrice = formatTableMoney2(s.stop_loss_price);
                return (
                    <>
                        {avg === '—' ? <span className="text-muted">—</span> : avg}
                        {complex && fillCost && fillCost !== '—' && fillCost !== avg && (
                            <div className="text-muted small">
                                Fill WAVG
                                {' '}
                                {fillCost}
                            </div>
                        )}
                        {complex && stopPrice !== '—' && s.stoploss_percent != null && (
                            <div className="text-muted small">
                                Stop-loss
                                {' '}
                                {stopPrice}
                                {' '}
                                (
                                {s.stoploss_percent}
                                %)
                            </div>
                        )}
                    </>
                );
            },
        },
        {
            id: 'latest_close',
            header: 'Latest Close',
            accessorFn: (row) => (
                complex
                    ? row.summary.daily_change_percent
                    : row.summary.latest_close
            ),
            sortUndefined: 'last',
            cell: ({ row }) => {
                const s = row.original.summary;
                const close = formatInrWhole(s.latest_close);
                const date = formatTransactionDateDisplay(s.latest_price_date);
                const dailyPct = s.daily_change_percent;
                const belowTrailingStop = isBelowTrailingStop(s);
                return (
                    <>
                        {close === '—' ? (
                            <span className="text-muted small">—</span>
                        ) : (
                            <div className="small fw-normal">
                                <span className={belowTrailingStop ? 'text-danger' : undefined}>
                                    {close}
                                </span>
                                {complex && dailyPct != null && !Number.isNaN(Number(dailyPct)) && (
                                    <>
                                        {' '}
                                        <span className={percentChangeColorClass(dailyPct)}>
                                            ({formatSignedPercent2(dailyPct)})
                                        </span>
                                    </>
                                )}
                            </div>
                        )}
                        {complex && date && (
                            <div className="text-muted small">{date}</div>
                        )}
                    </>
                );
            },
        },
        {
            id: 'unrealized_profit',
            header: 'Unrealized P/L',
            accessorFn: (row) => (
                complex
                    ? row.unrealized_gain_percent
                    : row.unrealized_profit
            ),
            sortUndefined: 'last',
            cell: ({ row }) => {
                const unrealized = row.original.unrealized_profit;
                const gainPct = row.original.unrealized_gain_percent;
                const formatted = formatSignedTableMoney2(unrealized);

                if (formatted === '—') {
                    return <span className="text-muted">—</span>;
                }

                return (
                    <>
                        <span className={percentChangeColorClass(unrealized)}>
                            {formatted}
                        </span>
                        {complex && gainPct != null && !Number.isNaN(Number(gainPct)) && (
                            <div className={`small fw-normal ${percentChangeColorClass(gainPct)}`}>
                                ({formatSignedPercent2(gainPct)})
                            </div>
                        )}
                    </>
                );
            },
        },
        {
            accessorKey: 'invested_amount',
            header: 'Invested',
            cell: ({ row, getValue }) => {
                const amount = formatTableMoney2(getValue());
                const stock = row.original.stock;
                const searchTerm = stock?.symbol || stock?.name || '';

                return (
                    <span className="lido-invested-cell d-inline-flex align-items-center gap-1">
                        <span>{amount}</span>
                        {searchTerm ? (
                            <Link
                                to="/transactions"
                                state={{ transactionSearch: searchTerm }}
                                className="lido-invested-tx-link"
                                title="View transactions"
                                aria-label="View transactions"
                            >
                                <InvestedTransactionsIcon />
                            </Link>
                        ) : null}
                    </span>
                );
            },
        },
        {
            id: 'target_amount',
            header: 'Target',
            meta: { columnMenuLabel: 'Position target / filled (OD-12)' },
            accessorFn: (row) => row.target_amount,
            cell: ({ row }) => {
                const target = formatTableMoney2(row.original.target_amount);
                const filled = formatTableMoney2(row.original.filled_amount);
                const remaining = formatTableMoney2(row.original.remaining_target_amount);
                if (target === '—') {
                    return <span className="text-muted">—</span>;
                }
                return (
                    <>
                        {target}
                        {complex && (
                            <>
                                <div className="text-muted small">
                                    Filled
                                    {' '}
                                    {filled}
                                </div>
                                <div className="text-muted small">
                                    Remaining
                                    {' '}
                                    {remaining}
                                </div>
                            </>
                        )}
                    </>
                );
            },
        },
        {
            id: 'fees',
            header: 'Fees',
            accessorFn: (row) => row.total_fees,
            cell: ({ row }) => {
                const fees = formatTableMoney2(row.original.total_fees);
                const pct = feesPercentOfInvested(row.original.total_fees, row.original.invested_amount);
                if (fees === '—') {
                    return <span className="text-muted">—</span>;
                }
                return (
                    <>
                        {fees}
                        {complex && pct != null && (
                            <div className="text-muted small">{pct}% of invested</div>
                        )}
                    </>
                );
            },
        },
        {
            id: 'xirr',
            header: 'XIRR',
            accessorFn: (row) => row.xirr,
            cell: ({ getValue }) => {
                const value = getValue();
                if (value === null || value === undefined || Number.isNaN(Number(value))) {
                    return <span className="text-muted">—</span>;
                }
                return `${Number(value).toFixed(2)}%`;
            },
        },
        {
            id: 'highest_close',
            header: 'Highest Close',
            meta: { columnMenuLabel: 'Highest Close' },
            accessorFn: (row) => (
                complex
                    ? ltpDrawdownFromHighPercent(
                        row.summary.latest_close,
                        row.summary.highest_close_since_buy,
                    )
                    : row.summary.highest_close_since_buy
            ),
            sortUndefined: 'last',
            cell: ({ row }) => {
                const s = row.original.summary;
                const value = formatTableMoney2(s.highest_close_since_buy);
                const ltpPct = ltpDrawdownFromHighPercent(s.latest_close, s.highest_close_since_buy);
                return (
                    <>
                        {value === '—' ? <span className="text-muted">—</span> : value}
                        {complex && ltpPct != null && (
                            <div className={`small fw-normal ${ltpDrawdownColorClass(ltpPct, s.stoploss_percent)}`}>
                                {formatLtpDrawdownLabel(ltpPct)}
                            </div>
                        )}
                        {complex && s.has_price_history === false && (
                            <div className="text-warning small">No price data</div>
                        )}
                    </>
                );
            },
        },
        {
            id: 'trailing_stop',
            header: 'Trailing Stop',
            accessorFn: (row) => row.summary.trailing_stop_price,
            cell: ({ row }) => {
                const s = row.original.summary;
                const price = formatTableMoney2(s.trailing_stop_price);
                const trailPct = s.portfolio_trailing_percent ?? null;
                return (
                    <>
                        {price === '—' ? <span className="text-muted">—</span> : price}
                        {complex && trailPct != null && (
                            <div className="text-muted small">
                                {trailPct}
                                % from peak close
                            </div>
                        )}
                    </>
                );
            },
        },
        {
            accessorKey: 'realized_profit',
            header: 'Realized P/L',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },
        {
            id: 'sell',
            header: '',
            enableSorting: false,
            enableHiding: false,
            meta: { columnMenuLabel: 'Sell' },
            cell: ({ row }) => {
                const belowTrailingStop = isBelowTrailingStop(row.original.summary);
                return (
                    <ComboButton
                        label="Sell"
                        variant={belowTrailingStop ? 'danger' : 'outline-danger'}
                        onPrimaryClick={() => handleSell(row.original)}
                        menuItems={[
                            {
                                label: 'Split/Bonus',
                                onClick: () => handleCorporateAction(row.original),
                            },
                            ...(row.original.is_unmanaged && handleAdopt ? [{
                                label: 'Adopt',
                                onClick: () => handleAdopt(row.original),
                            }] : []),
                        ]}
                    />
                );
            },
        },
    ];
}

export default function HoldingsPage() {
    const navigate = useNavigate();
    const [holdings, setHoldings] = useState([]);
    const [loading, setLoading] = useState(true);
    const [viewMode, setViewMode] = useState(loadHoldingsViewMode);
    const [adoptHolding, setAdoptHolding] = useState(null);
    const [adoptStrategies, setAdoptStrategies] = useState([]);
    const [adoptStrategyId, setAdoptStrategyId] = useState('');
    const [adoptBusy, setAdoptBusy] = useState(false);
    const [adoptError, setAdoptError] = useState('');

    const handleCorporateAction = useCallback((holding) => {
        const stockRow = holding.stock || {};
        navigate('/corporate-action', {
            state: {
                corporateActionStock: {
                    stock_id: holding.stock_id,
                    symbol: stockRow.symbol || '',
                    name: stockRow.name || '',
                    exchange: stockRow.exchange || 'NSE',
                },
            },
        });
    }, [navigate]);

    const handleAdopt = useCallback(async (holding) => {
        setAdoptHolding(holding);
        setAdoptError('');
        setAdoptStrategyId('');
        try {
            const res = await api.get('/v1/strategy-registry');
            const rows = res.data?.data || [];
            const enabled = rows.filter((row) => {
                const meta = row.metadata || {};
                return meta.is_enabled || row.status === 'active' || meta.status === 'active';
            });
            setAdoptStrategies(enabled);
            const firstId = enabled[0]?.metadata?.legacy_id || enabled[0]?.legacy_id || enabled[0]?.id;
            if (firstId) {
                setAdoptStrategyId(String(firstId));
            }
        } catch {
            setAdoptStrategies([]);
            setAdoptError('Could not load strategies.');
        }
    }, []);

    const handleSell = useCallback((holding) => {
        const prefill = buildSellPrefillFromHolding(holding);
        if (!prefill) {
            return;
        }
        navigate('/transactions', { state: { sellPrefill: prefill } });
    }, [navigate]);

    const handleViewModeChange = useCallback((mode) => {
        setViewMode(mode);
        saveHoldingsViewMode(mode);
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const holdingsRes = await api.get('/holdings');
            setHoldings(holdingsRes.data.data || []);
        } finally {
            setLoading(false);
        }
    }, []);

    const handleAdoptConfirm = useCallback(async () => {
        if (!adoptHolding || !adoptStrategyId) {
            return;
        }
        setAdoptBusy(true);
        setAdoptError('');
        try {
            await api.post(`/holdings/${adoptHolding.id}/adopt`, {
                strategy_id: Number(adoptStrategyId),
            });
            setAdoptHolding(null);
            await load();
        } catch (e) {
            const msg = e?.response?.data?.errors?.strategy_id?.[0]
                || e?.response?.data?.errors?.holding_id?.[0]
                || e?.response?.data?.message
                || 'Adoption failed.';
            setAdoptError(msg);
        } finally {
            setAdoptBusy(false);
        }
    }, [adoptHolding, adoptStrategyId, load]);

    useEffect(() => { load(); }, [load]);
    usePortfolioChanged(load);

    const tableData = useMemo(() => holdings.map((h) => ({
        ...h,
        summary: h.stoploss_summary || {},
    })), [holdings]);

    const complexColumns = useMemo(
        () => buildHoldingsColumns(true, handleSell, handleCorporateAction, handleAdopt),
        [handleSell, handleCorporateAction, handleAdopt],
    );
    const simpleColumns = useMemo(
        () => buildHoldingsColumns(false, handleSell, handleCorporateAction, handleAdopt),
        [handleSell, handleCorporateAction, handleAdopt],
    );

    const sharedTableProps = useMemo(() => ({
        data: tableData,
        defaultColumnOrder: HOLDINGS_COLUMN_ORDER,
        defaultColumnVisibility: HOLDINGS_DEFAULT_COLUMN_VISIBILITY,
    }), [tableData]);

    const complexController = useDataTableController({
        ...sharedTableProps,
        columns: complexColumns,
        storageKey: 'holdings',
        tableClassName: 'table table-sm mb-0 datatable-table',
    });

    const simpleController = useDataTableController({
        ...sharedTableProps,
        columns: simpleColumns,
        storageKey: 'holdings-simple',
        tableClassName: 'table table-sm mb-0 datatable-table',
    });

    const activeController = viewMode === 'simple' ? simpleController : complexController;
    const emptyMessage = 'No open holdings. Add a buy transaction first.';

    return (
        <div>
            <div className="mb-3">
                <h1 className="h3 mb-1">Portfolio</h1>
                <p className="text-muted small mb-0">
                    Manage existing holdings — allocation, returns, stops, and position actions.
                    Stock discovery belongs on <Link to="/candidates">Discovery</Link>; research on{' '}
                    <Link to="/watchlist">Watchlist</Link>.
                </p>
            </div>
        <div className="card">
            <div className="card-header d-flex justify-content-between align-items-center gap-2">
                <div className="mb-0">
                    Holdings
                    {!loading ? (
                        <span className="lido-card-title-count">({tableData.length})</span>
                    ) : null}
                </div>
                <div className="d-flex align-items-center gap-2 ms-auto">
                    <SegmentToggle
                        compact
                        value={viewMode}
                        onChange={handleViewModeChange}
                        options={HOLDINGS_VIEW_OPTIONS}
                        ariaLabel="Holdings table view"
                    />
                    <DataTableColumnMenu controller={activeController} />
                </div>
            </div>
            <div className="card-body">
                <div className={viewMode === 'complex' ? '' : 'd-none'} aria-hidden={viewMode !== 'complex'}>
                    <DataTableView
                        controller={complexController}
                        loading={loading}
                        emptyMessage={emptyMessage}
                    />
                </div>
                <div className={viewMode === 'simple' ? '' : 'd-none'} aria-hidden={viewMode !== 'simple'}>
                    <DataTableView
                        controller={simpleController}
                        loading={loading}
                        emptyMessage={emptyMessage}
                    />
                </div>
            </div>
        </div>
            {adoptHolding ? (
                <div className="modal d-block" tabIndex={-1} role="dialog" style={{ background: 'rgba(0,0,0,0.4)' }}>
                    <div className="modal-dialog">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">
                                    Adopt
                                    {' '}
                                    {adoptHolding.stock?.symbol || 'holding'}
                                </h5>
                                <button
                                    type="button"
                                    className="btn-close"
                                    aria-label="Close"
                                    onClick={() => setAdoptHolding(null)}
                                    disabled={adoptBusy}
                                />
                            </div>
                            <div className="modal-body">
                                <p className="small text-muted">
                                    Move this unmanaged position into one strategy. Entry history and risk windows stay
                                    continuous. Adoption into a strategy that already owns this stock is blocked until
                                    merge rules are specified.
                                </p>
                                <label className="form-label" htmlFor="adopt-strategy">
                                    Destination strategy
                                </label>
                                <select
                                    id="adopt-strategy"
                                    className="form-select"
                                    value={adoptStrategyId}
                                    onChange={(e) => setAdoptStrategyId(e.target.value)}
                                    disabled={adoptBusy || adoptStrategies.length === 0}
                                >
                                    {adoptStrategies.length === 0 ? (
                                        <option value="">No enabled strategies</option>
                                    ) : adoptStrategies.map((row) => {
                                        const id = row.metadata?.legacy_id ?? row.legacy_id ?? row.id;
                                        return (
                                            <option key={String(id)} value={String(id)}>
                                                {row.name || `Strategy #${id}`}
                                            </option>
                                        );
                                    })}
                                </select>
                                {adoptError ? (
                                    <div className="alert alert-danger mt-3 mb-0 py-2 small">{adoptError}</div>
                                ) : null}
                            </div>
                            <div className="modal-footer">
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary"
                                    onClick={() => setAdoptHolding(null)}
                                    disabled={adoptBusy}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={handleAdoptConfirm}
                                    disabled={adoptBusy || !adoptStrategyId}
                                >
                                    {adoptBusy ? 'Adopting…' : 'Adopt'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
