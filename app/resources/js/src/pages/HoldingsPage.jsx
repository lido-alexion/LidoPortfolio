import React, { useCallback, useEffect, useMemo, useState } from 'react';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
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
    'latest_close',
    'unrealized_profit',
    'invested_amount',
    'fees',
    'xirr',
    'highest_close',
    'quantity',
    'avg_buy_price',
    'trailing_stop',
    'realized_profit',
    'prices',
    'sell',
];

const HOLDINGS_DEFAULT_COLUMN_VISIBILITY = {
    fees: false,
    realized_profit: false,
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

function buildHoldingsColumns(complex, handleSell) {
    return [
        {
            id: 'stock',
            accessorKey: 'stock.symbol',
            header: 'Stock',
            cell: ({ row }) => {
                const s = row.original.summary;
                const since = formatTransactionDateDisplay(s.first_buy_date);
                const belowTrailingStop = isBelowTrailingStop(s);
                return (
                    <>
                        <strong className={belowTrailingStop ? 'text-danger' : undefined}>
                            {row.original.stock?.symbol}
                        </strong>
                        {complex && since && (
                            <div className="text-muted small">Since {since}</div>
                        )}
                    </>
                );
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
            cell: ({ getValue }) => formatTableMoney2(getValue()),
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
                return (
                    <>
                        {price === '—' ? <span className="text-muted">—</span> : price}
                        {complex && s.stoploss_percent != null && (
                            <div className="text-muted small">{s.stoploss_percent}% stop</div>
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
            id: 'prices',
            header: 'Prices',
            enableSorting: false,
            enableHiding: false,
            cell: ({ row }) => (
                <Link
                    className="lido-table-link"
                    to={`/holdings/${row.original.stock_id}/prices`}
                >
                    {complex ? 'OHLCV' : 'OHLCV >'}
                </Link>
            ),
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
                    <button
                        type="button"
                        className={`btn btn-sm ${belowTrailingStop ? 'btn-danger' : 'btn-outline-danger'}`}
                        onClick={() => handleSell(row.original)}
                    >
                        Sell
                    </button>
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

    useEffect(() => { load(); }, [load]);
    usePortfolioChanged(load);

    const tableData = useMemo(() => holdings.map((h) => ({
        ...h,
        summary: h.stoploss_summary || {},
    })), [holdings]);

    const complexColumns = useMemo(
        () => buildHoldingsColumns(true, handleSell),
        [handleSell],
    );
    const simpleColumns = useMemo(
        () => buildHoldingsColumns(false, handleSell),
        [handleSell],
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
    });

    const simpleController = useDataTableController({
        ...sharedTableProps,
        columns: simpleColumns,
        storageKey: 'holdings-simple',
    });

    const activeController = viewMode === 'simple' ? simpleController : complexController;
    const emptyMessage = 'No open holdings. Add a buy transaction first.';

    return (
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
    );
}
