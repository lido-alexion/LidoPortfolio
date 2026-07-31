import React from 'react';

function cellClass(cell) {
    if (!cell) {
        return 'backtest-timeline-cell backtest-timeline-cell--empty';
    }
    if (cell.profitable === true) {
        return 'backtest-timeline-cell backtest-timeline-cell--win';
    }
    if (cell.profitable === false) {
        return 'backtest-timeline-cell backtest-timeline-cell--loss';
    }
    return 'backtest-timeline-cell backtest-timeline-cell--open';
}

function cellTitle(cell, date) {
    if (!cell) return date;
    const parts = [`Day ${cell.day}`, date];
    if (cell.return_pct != null) {
        parts.push(`${Number(cell.return_pct).toFixed(2)}%`);
    }
    if (cell.profitable === true) parts.push('Profitable');
    if (cell.profitable === false) parts.push('Loss');
    if (cell.profitable === null) parts.push('Open');
    return parts.join(' · ');
}

export default function BacktestTradeTimeline({ timeline }) {
    const columns = timeline?.columns || [];
    const rows = timeline?.rows || [];

    if (!columns.length || !rows.length) {
        return (
            <div className="text-muted small py-3">
                Trade timeline is available after the backtest completes.
            </div>
        );
    }

    return (
        <div className="backtest-timeline-wrap">
            <table className="table table-sm table-bordered backtest-timeline-table mb-0">
                <thead>
                    <tr>
                        <th className="backtest-timeline-sticky-col">Symbol</th>
                        {columns.map((date) => (
                            <th key={date} className="backtest-timeline-date-col text-muted">
                                {date.slice(5)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.symbol}>
                            <td className="backtest-timeline-sticky-col fw-semibold">{row.symbol}</td>
                            {row.cells.map((cell, idx) => (
                                <td
                                    key={`${row.symbol}-${columns[idx]}`}
                                    className={cellClass(cell)}
                                    title={cellTitle(cell, columns[idx])}
                                >
                                    {cell ? cell.day : ''}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
