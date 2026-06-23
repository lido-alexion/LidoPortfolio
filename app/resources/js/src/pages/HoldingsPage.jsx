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

const HOLDINGS_COLUMN_ORDER = [
    'stock',
    'latest_close',
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

export default function HoldingsPage() {
    const navigate = useNavigate();
    const [holdings, setHoldings] = useState([]);
    const [loading, setLoading] = useState(true);

    const handleSell = useCallback((holding) => {
        const prefill = buildSellPrefillFromHolding(holding);
        if (!prefill) {
            return;
        }
        navigate('/transactions', { state: { sellPrefill: prefill } });
    }, [navigate]);

    const load = async () => {
        setLoading(true);
        try {
            const holdingsRes = await api.get('/holdings');
            setHoldings(holdingsRes.data.data || []);
        } finally {
            setLoading(false);
        }
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
                const belowTrailingStop = isBelowTrailingStop(s);
                return (
                    <>
                        <strong className={belowTrailingStop ? 'text-danger' : undefined}>
                            {row.original.stock?.symbol}
                        </strong>
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
                        {pct != null && (
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
    ], [handleSell]);

    return (
        <DataTableCard
            title="Holdings"
            columns={columns}
            data={tableData}
            storageKey="holdings"
            loading={loading}
            defaultColumnOrder={HOLDINGS_COLUMN_ORDER}
            defaultColumnVisibility={HOLDINGS_DEFAULT_COLUMN_VISIBILITY}
            emptyMessage="No open holdings. Add a buy transaction first."
        />
    );
}
