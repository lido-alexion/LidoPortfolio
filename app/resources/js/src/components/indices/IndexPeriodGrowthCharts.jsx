import React, { useMemo } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { colorForIndex } from '../../utils/indexChartHelpers';
import { formatSignedPercent2 } from '../../utils/tableFormat';

const PERIODS = [
    { key: '6m', label: '6 months' },
    { key: '3m', label: '3 months' },
    { key: '1m', label: '1 month' },
    { key: '15d', label: '15 days' },
];

const tooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '4px',
    color: 'var(--lido-chart-tooltip-text)',
    fontSize: 11,
    padding: '4px 6px',
};

function PeriodBarCard({ title, data }) {
    const manyBars = data.length > 10;
    const chartHeight = Math.max(220, Math.min(420, 120 + data.length * 14));

    return (
        <div className="card indices-period-card">
            <div className="card-header py-2">
                <div className="fw-semibold small mb-0">{title}</div>
            </div>
            <div className="card-body pt-2">
                {data.length === 0 ? (
                    <div className="text-muted small py-4 text-center">No growth data for selected indexes</div>
                ) : (
                    <div className="indices-period-chart-canvas" style={{ height: chartHeight, minHeight: chartHeight }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={data}
                                margin={{
                                    top: 4,
                                    right: 8,
                                    left: 0,
                                    bottom: manyBars ? 64 : 36,
                                }}
                            >
                                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                <XAxis
                                    dataKey="shortName"
                                    tick={{ fontSize: 9, fill: 'var(--lido-text-muted)' }}
                                    stroke="var(--lido-border-strong)"
                                    interval={0}
                                    angle={-40}
                                    textAnchor="end"
                                    height={manyBars ? 64 : 40}
                                />
                                <YAxis
                                    tickFormatter={(value) => formatSignedPercent2(value)}
                                    width={48}
                                    tick={{ fontSize: 10, fill: 'var(--lido-text-muted)' }}
                                    stroke="var(--lido-border-strong)"
                                />
                                <Tooltip
                                    contentStyle={tooltipStyle}
                                    labelStyle={{ color: 'var(--lido-chart-tooltip-label)', fontWeight: 600, marginBottom: 2 }}
                                    formatter={(value, _name, props) => [
                                        formatSignedPercent2(value),
                                        props?.payload?.name || 'Growth',
                                    ]}
                                    labelFormatter={(_label, payload) => payload?.[0]?.payload?.name || ''}
                                />
                                <Bar dataKey="growth" name="Growth" radius={[3, 3, 0, 0]} maxBarSize={28}>
                                    {data.map((row) => (
                                        <Cell key={row.symbol} fill={row.color} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function IndexPeriodGrowthCharts({ indexes = [], selectedSymbols }) {
    const selected = selectedSymbols instanceof Set ? selectedSymbols : null;

    const charts = useMemo(() => PERIODS.map((period) => {
        const rows = (indexes || [])
            .filter((index) => !selected || selected.has(index.symbol))
            .map((index, idx) => {
                const growth = index.change_percent?.[period.key];
                if (growth == null || Number.isNaN(Number(growth))) {
                    return null;
                }
                return {
                    symbol: index.symbol,
                    name: index.name,
                    shortName: index.symbol.replace(/^NIFTY/, 'N').replace(/^BSE/, 'B'),
                    growth: Number(growth),
                    color: colorForIndex(index.symbol, idx),
                };
            })
            .filter(Boolean)
            .sort((a, b) => b.growth - a.growth);

        return { ...period, data: rows };
    }), [indexes, selected]);

    if (!indexes?.length) {
        return null;
    }

    return (
        <div className="indices-period-stack mt-3">
            <div className="text-muted small mb-2">
                Period growth for selected indexes (broad + sector). Charts follow the legend above.
            </div>
            {charts.map((chart) => (
                <PeriodBarCard
                    key={chart.key}
                    title={`${chart.label} growth`}
                    data={chart.data}
                />
            ))}
        </div>
    );
}
