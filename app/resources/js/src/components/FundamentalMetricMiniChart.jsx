import React, { useMemo } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const tooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '6px',
    color: 'var(--lido-chart-tooltip-text)',
    padding: '8px 10px',
    fontSize: '0.8125rem',
};

export default function FundamentalMetricMiniChart({ label, points }) {
    const data = useMemo(
        () => (points || [])
            .filter((p) => p.value != null)
            .map((p) => ({
                period: p.period_end || p.as_of,
                value: Number(p.value),
            })),
        [points],
    );

    if (!data.length) {
        return null;
    }

    return (
        <div>
            {label ? <h3 className="h6 mb-2">{label}</h3> : null}
            <div style={{ height: 200, minHeight: 200 }}>
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--lido-chart-grid)" />
                        <XAxis dataKey="period" tick={{ fontSize: 10 }} />
                        <YAxis tick={{ fontSize: 10 }} width={48} />
                        <Tooltip
                            contentStyle={tooltipStyle}
                            formatter={(v) => [Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }), label]}
                        />
                        <Line type="monotone" dataKey="value" stroke="var(--bs-primary)" strokeWidth={2} dot={false} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}
