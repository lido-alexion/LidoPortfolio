import React, { useMemo, useState } from 'react';
import {
    Bar,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import PatternMatchesList from '../PatternMatchesList';
import {
    formatInrCompactWhole,
    formatInrWhole,
    formatSignedPercent2,
    formatTableInteger,
} from '../../utils/tableFormat';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../../utils/transactionDate';
import {
    buildPriceVolumeChartData,
    filterByTimeRange,
    formatChartRangeClampHint,
    normalizeOhlcvRows,
    xAxisTickInterval,
} from '../../utils/ohlcvChartData';
import { scanOhlcv } from '../../utils/patternDetection';
import {
    DEFAULT_SAMPLING,
    DEFAULT_TIME_RANGE,
} from './priceVolumeChartTypes';
import PriceVolumeChartControls from './PriceVolumeChartControls';

const chartTooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '6px',
    color: 'var(--lido-chart-tooltip-text)',
};

const chartTooltipLabelStyle = {
    color: 'var(--lido-chart-tooltip-label)',
    fontWeight: 600,
    marginBottom: 4,
};

function formatVolumeAxis(value) {
    const num = Number(value);
    if (Number.isNaN(num)) {
        return '';
    }
    if (num >= 1_000_000) {
        return `${(num / 1_000_000).toFixed(1)}M`;
    }
    if (num >= 1_000) {
        return `${(num / 1_000).toFixed(1)}K`;
    }
    return formatTableInteger(num);
}

export default function PriceVolumeChart({
    rows = [],
    loading = false,
    emptyMessage = 'No price data to chart.',
    defaultTimeRange = DEFAULT_TIME_RANGE,
    defaultSampling = DEFAULT_SAMPLING,
    height = 300,
    showControls = true,
    title = 'Close & Volume',
    className = '',
}) {
    const [timeRange, setTimeRange] = useState(defaultTimeRange);
    const [sampling, setSampling] = useState(defaultSampling);

    const { series, meta } = useMemo(
        () => buildPriceVolumeChartData(rows, { timeRange, sampling }),
        [rows, timeRange, sampling],
    );

    const windowPatternMatches = useMemo(() => {
        if (loading || !rows?.length) {
            return [];
        }
        const normalized = normalizeOhlcvRows(rows);
        const { filtered } = filterByTimeRange(normalized, timeRange);
        return scanOhlcv(filtered, { actionableOnly: false });
    }, [rows, timeRange, loading]);

    const clampHint = formatChartRangeClampHint(meta);
    const manyPoints = series.length > 60;
    const showDots = series.length > 0 && series.length <= 60;
    const tickInterval = xAxisTickInterval(series.length);
    const bottomMargin = manyPoints ? 48 : 28;

    return (
        <div className={['lido-price-volume-chart card', className].filter(Boolean).join(' ')}>
            <div className="card-header py-2">
                <div className="mb-0">{title}</div>
            </div>
            <div className="card-body pb-2">
                {loading ? (
                    <div className="text-muted small py-4 text-center">Loading chart…</div>
                ) : series.length === 0 ? (
                    <div className="text-muted small py-4 text-center">{emptyMessage}</div>
                ) : (
                    <div
                        className="lido-price-volume-chart-canvas"
                        style={{ width: '100%', height, minHeight: height }}
                    >
                        <ResponsiveContainer width="100%" height="100%">
                            <ComposedChart
                                data={series}
                                margin={{ top: 8, right: 48, left: 4, bottom: bottomMargin }}
                            >
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis
                                    dataKey="date"
                                    tickFormatter={(value) => formatChartAxisDate(value) || value}
                                    tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                    stroke="var(--lido-border-strong)"
                                    minTickGap={36}
                                    interval={tickInterval}
                                    angle={manyPoints ? -40 : 0}
                                    textAnchor={manyPoints ? 'end' : 'middle'}
                                    height={manyPoints ? 48 : 28}
                                />
                                <YAxis
                                    yAxisId="price"
                                    orientation="left"
                                    tickFormatter={(value) => formatInrCompactWhole(value)}
                                    width={72}
                                    tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                    stroke="var(--lido-border-strong)"
                                />
                                <YAxis
                                    yAxisId="volume"
                                    orientation="right"
                                    tickFormatter={formatVolumeAxis}
                                    width={48}
                                    tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                    stroke="var(--lido-border-strong)"
                                />
                                <Tooltip
                                    contentStyle={chartTooltipStyle}
                                    labelStyle={chartTooltipLabelStyle}
                                    itemStyle={{ color: 'var(--lido-chart-tooltip-text)' }}
                                    labelFormatter={(label) => formatTransactionDateDisplay(label) || label}
                                    formatter={(value, name, props) => {
                                        if (name === 'close') {
                                            const change = props?.payload?.changePercent;
                                            const changeLabel = change != null && !Number.isNaN(Number(change))
                                                ? ` (${formatSignedPercent2(change)})`
                                                : '';
                                            return [`${formatInrWhole(value)}${changeLabel}`, 'Close'];
                                        }
                                        if (name === 'volume') {
                                            return [formatTableInteger(value), 'Volume'];
                                        }
                                        return [value, name];
                                    }}
                                />
                                <Bar
                                    yAxisId="volume"
                                    dataKey="volume"
                                    name="volume"
                                    barSize={series.length > 80 ? 4 : series.length > 40 ? 8 : 12}
                                    isAnimationActive={series.length <= 120}
                                >
                                    {series.map((point) => (
                                        <Cell key={point.date} fill={point.volumeColor} />
                                    ))}
                                </Bar>
                                <Line
                                    yAxisId="price"
                                    type="monotone"
                                    dataKey="close"
                                    name="close"
                                    stroke="#0d6efd"
                                    strokeWidth={2}
                                    dot={showDots}
                                    isAnimationActive={series.length <= 120}
                                />
                            </ComposedChart>
                        </ResponsiveContainer>
                    </div>
                )}
                {clampHint ? (
                    <p className="text-muted small mb-0 mt-2">{clampHint}</p>
                ) : null}
                {!loading && series.length > 0 && meta.samplingStep > 1 ? (
                    <p className="text-muted small mb-0 mt-1">
                        {meta.pointCount} points · {meta.samplingStep}-row buckets · volume summed per bucket
                    </p>
                ) : null}
                {!loading && series.length > 0 ? (
                    <div className="mt-3 pt-2 border-top">
                        <PatternMatchesList
                            matches={windowPatternMatches}
                            title="Possible patterns on this window"
                            emptyMessage="No patterns detected on the latest bar in this range."
                        />
                    </div>
                ) : null}
            </div>
            {showControls ? (
                <div className="card-footer bg-transparent border-top-0 pt-0 pb-3">
                    <PriceVolumeChartControls
                        timeRange={timeRange}
                        onTimeRangeChange={setTimeRange}
                        sampling={sampling}
                        onSamplingChange={setSampling}
                        disabled={loading}
                    />
                </div>
            ) : null}
        </div>
    );
}
