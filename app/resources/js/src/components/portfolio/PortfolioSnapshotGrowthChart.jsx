import React, { useMemo } from 'react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
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

function GrowthTooltip({ active, payload, label }) {
    if (!active || !payload?.length) {
        return null;
    }
    const row = payload[0]?.payload || {};
    return (
        <div style={tooltipStyle}>
            <div className="fw-semibold mb-1">{formatTransactionDateDisplay(label) || label}</div>
            <div>Portfolio: {formatInrWhole(row.portfolio_value)}</div>
            <div>Invested: {formatInrWhole(row.invested_value)}</div>
            <div>Unrealized P/L: {formatInrWhole(row.unrealized_pl)}</div>
        </div>
    );
}

export default function PortfolioSnapshotGrowthChart({ snapshots }) {
    const points = useMemo(() => (snapshots || []).map((row) => {
        const date = typeof row.snapshot_date === 'string'
            ? row.snapshot_date.slice(0, 10)
            : row.snapshot_date;
        const portfolioValue = Number(row.portfolio_value || 0);
        const investedValue = Number(row.invested_value || 0);

        return {
            date,
            portfolio_value: portfolioValue,
            invested_value: investedValue,
            unrealized_pl: portfolioValue - investedValue,
        };
    }), [snapshots]);

    const manyPoints = points.length > 24;
    const bottomMargin = manyPoints ? 52 : 28;
    const showDots = points.length > 0 && points.length <= 5;

    if (!points.length) {
        return (
            <div className="text-muted small py-4 text-center">
                No snapshot history to chart yet.
            </div>
        );
    }

    return (
        <div style={{ width: '100%', height: 280, minHeight: 280 }}>
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={points} margin={{ top: 8, right: 16, left: 4, bottom: bottomMargin }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--lido-border-strong)" />
                    <XAxis
                        dataKey="date"
                        tickFormatter={formatChartAxisDate}
                        tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                        stroke="var(--lido-border-strong)"
                        minTickGap={36}
                        interval="preserveStartEnd"
                        angle={manyPoints ? -40 : 0}
                        textAnchor={manyPoints ? 'end' : 'middle'}
                        height={manyPoints ? 48 : 28}
                    />
                    <YAxis
                        tickFormatter={(v) => formatInrCompactWhole(v)}
                        width={80}
                        tick={{ fontSize: 10, fill: 'var(--lido-text-muted)' }}
                        stroke="var(--lido-border-strong)"
                    />
                    <Tooltip content={<GrowthTooltip />} />
                    <Legend />
                    <Line
                        type="monotone"
                        dataKey="portfolio_value"
                        name="Portfolio value"
                        stroke="#0d6efd"
                        strokeWidth={2}
                        dot={showDots}
                        activeDot={{ r: 4 }}
                    />
                    <Line
                        type="monotone"
                        dataKey="invested_value"
                        name="Invested value"
                        stroke="#198754"
                        strokeWidth={2}
                        dot={showDots}
                        activeDot={{ r: 4 }}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
