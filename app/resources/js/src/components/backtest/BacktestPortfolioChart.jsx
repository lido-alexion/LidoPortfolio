import React, { useMemo } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatInrCompactWhole, formatInrWhole } from '../../utils/tableFormat';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../../utils/transactionDate';

const tooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '6px',
    color: 'var(--lido-chart-tooltip-text)',
    padding: '8px 10px',
    fontSize: '0.8125rem',
};

function PortfolioTooltip({ active, payload, label }) {
    if (!active || !payload?.length) {
        return null;
    }
    const row = payload[0]?.payload || {};
    return (
        <div style={tooltipStyle}>
            <div className="fw-semibold mb-1">{formatTransactionDateDisplay(label) || label}</div>
            <div>Portfolio: {formatInrWhole(row.portfolio_value)}</div>
            <div>Cash: {formatInrWhole(row.cash)}</div>
            <div>Invested: {formatInrWhole(row.invested_value)}</div>
        </div>
    );
}

export default function BacktestPortfolioChart({ chart, snapshots }) {
    const initialCapital = chart?.initial_capital ?? null;
    const points = useMemo(() => {
        const source = chart?.points?.length ? chart.points : (snapshots || []);
        return (source || []).map((p) => ({
            date: p.date,
            portfolio_value: Number(p.portfolio_value),
            cash: Number(p.cash),
            invested_value: Number(p.invested_value),
        }));
    }, [chart, snapshots]);

    if (!points.length) {
        return (
            <div className="text-muted small py-4 text-center">
                Portfolio chart appears after daily snapshots are generated.
            </div>
        );
    }

    return (
        <div style={{ height: 320, minHeight: 320 }}>
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={points} margin={{ top: 8, right: 16, left: 0, bottom: 4 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--lido-border-strong)" />
                    <XAxis
                        dataKey="date"
                        tickFormatter={formatChartAxisDate}
                        tick={{ fontSize: 10, fill: 'var(--lido-text-muted)' }}
                        stroke="var(--lido-border-strong)"
                        minTickGap={24}
                    />
                    <YAxis
                        tickFormatter={(v) => formatInrCompactWhole(v)}
                        width={56}
                        tick={{ fontSize: 10, fill: 'var(--lido-text-muted)' }}
                        stroke="var(--lido-border-strong)"
                    />
                    <Tooltip content={<PortfolioTooltip />} />
                    {initialCapital != null && (
                        <ReferenceLine
                            y={Number(initialCapital)}
                            stroke="var(--lido-text-muted)"
                            strokeDasharray="4 4"
                            label={{
                                value: 'Initial capital',
                                position: 'insideTopRight',
                                fill: 'var(--lido-text-muted)',
                                fontSize: 10,
                            }}
                        />
                    )}
                    <Line
                        type="monotone"
                        dataKey="portfolio_value"
                        name="Portfolio value"
                        stroke="#0d6efd"
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 4 }}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
