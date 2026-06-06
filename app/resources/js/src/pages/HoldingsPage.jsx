import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import { DataTableCard } from '../components/DataTable';
import { formatTransactionDateDisplay } from '../utils/transactionDate';
import { buildSellPrefillFromHolding } from '../utils/sellTransactionPrefill';
import {
    formatInrWhole,
    formatLtpDrawdownLabel,
    formatSignedPercentRounded,
    formatTableInteger,
    formatTableMoney2,
    ltpDrawdownColorClass,
    ltpDrawdownFromHighPercent,
    percentChangeColorClass,
    percentGainLossFromAvgBuy,
} from '../utils/tableFormat';

const HOLDINGS_COLUMN_ORDER = [
    'stock',
    'quantity',
    'avg_buy_price',
    'latest_close',
    'invested_amount',
    'xirr',
    'highest_close',
    'trailing_stop',
    'realized_profit',
    'prices',
    'sell',
];

export default function HoldingsPage() {
    const navigate = useNavigate();
    const [holdings, setHoldings] = useState([]);

    const handleSell = useCallback((holding) => {
        const prefill = buildSellPrefillFromHolding(holding);
        if (!prefill) {
            return;
        }
        navigate('/transactions', { state: { sellPrefill: prefill } });
    }, [navigate]);

    const load = async () => {
        const holdingsRes = await api.get('/holdings');
        setHoldings(holdingsRes.data.data || []);
    };

    useEffect(() => { load(); }, []);

    const tableData = useMemo(() => holdings.map((h) => ({
        ...h,
        summary: h.stoploss_summary || {},
    })), [holdings]);

    const columns = useMemo(() => [
        {
            id: 'stock',
            accessorKey: 'stock.symbol',
            header: 'Stock',
            cell: ({ row }) => {
                const s = row.original.summary;
                const since = formatTransactionDateDisplay(s.first_buy_date);
                return (
                    <>
                        <strong>{row.original.stock?.symbol}</strong>
                        {since && (
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
            accessorFn: (row) => row.summary.latest_close,
            cell: ({ row }) => {
                const s = row.original.summary;
                const close = formatInrWhole(s.latest_close);
                const date = formatTransactionDateDisplay(s.latest_price_date);
                const pct = percentGainLossFromAvgBuy(s.latest_close, row.original.avg_buy_price);
                const latestCloseNum = Number(s.latest_close);
                const trailingStopNum = Number(s.trailing_stop_price);
                const belowTrailingStop = !Number.isNaN(latestCloseNum)
                    && !Number.isNaN(trailingStopNum)
                    && latestCloseNum < trailingStopNum;
                return (
                    <>
                        {close === '—' ? (
                            <span className="text-muted small">—</span>
                        ) : (
                            <div className="small fw-normal">
                                <span className={belowTrailingStop ? 'text-danger' : undefined}>
                                    {close}
                                </span>
                                {pct != null && (
                                    <>
                                        {' '}
                                        <span className={percentChangeColorClass(pct)}>
                                            ({formatSignedPercentRounded(pct)})
                                        </span>
                                    </>
                                )}
                            </div>
                        )}
                        {date && (
                            <div className="text-muted small">{date}</div>
                        )}
                    </>
                );
            },
        },
        {
            accessorKey: 'invested_amount',
            header: 'Invested',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
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
            header: () => (
                <div className="lido-col-header-stack">
                    <span>Highest Close</span>
                    <span className="lido-col-header-sub">(since buy)</span>
                </div>
            ),
            meta: { columnMenuLabel: 'Highest Close' },
            accessorFn: (row) => row.summary.highest_close_since_buy,
            cell: ({ row }) => {
                const s = row.original.summary;
                const value = formatTableMoney2(s.highest_close_since_buy);
                const ltpPct = ltpDrawdownFromHighPercent(s.latest_close, s.highest_close_since_buy);
                return (
                    <>
                        {value === '—' ? <span className="text-muted">—</span> : value}
                        {ltpPct != null && (
                            <div className={`small fw-normal ${ltpDrawdownColorClass(ltpPct, s.stoploss_percent)}`}>
                                {formatLtpDrawdownLabel(ltpPct)}
                            </div>
                        )}
                        {s.has_price_history === false && (
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
                        {s.stoploss_percent != null && (
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
            cell: ({ row }) => {
                const s = row.original.summary;
                const count = s.price_row_count > 0 ? ` (${s.price_row_count})` : '';
                return (
                    <Link
                        className="lido-table-link"
                        to={`/holdings/${row.original.stock_id}/prices`}
                    >
                        OHLCV{count}
                    </Link>
                );
            },
        },
        {
            id: 'sell',
            header: '',
            enableSorting: false,
            enableHiding: false,
            meta: { columnMenuLabel: 'Sell' },
            cell: ({ row }) => (
                <button
                    type="button"
                    className="btn btn-sm btn-outline-danger"
                    onClick={() => handleSell(row.original)}
                >
                    Sell
                </button>
            ),
        },
    ], [handleSell]);

    return (
        <DataTableCard
            title="Holdings"
            columns={columns}
            data={tableData}
            storageKey="holdings"
            defaultColumnOrder={HOLDINGS_COLUMN_ORDER}
            emptyMessage="No open holdings. Add a buy transaction first."
        />
    );
}
