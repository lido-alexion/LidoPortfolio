import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { usePortfolio } from '../../context/PortfolioContext';
import {
    colorForIndex,
    chartableIndexes,
    groupIndexesByTier,
    mergeComparisonSeries,
} from '../../utils/indexChartHelpers';
import {
    loadIndexLegendSelection,
    saveIndexLegendSelection,
} from '../../utils/indexLegendPrefs';
import { formatSignedPercent2 } from '../../utils/tableFormat';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../../utils/transactionDate';
import IndexPeriodGrowthCharts from './IndexPeriodGrowthCharts';

function CompactComparisonTooltip({ active, payload, label }) {
    if (!active || !payload?.length) {
        return null;
    }

    const items = payload
        .filter((entry) => entry.value != null && !Number.isNaN(Number(entry.value)))
        .sort((a, b) => Number(b.value) - Number(a.value));

    if (items.length === 0) {
        return null;
    }

    return (
        <div className="indices-comparison-tooltip">
            <div className="indices-comparison-tooltip-date">
                {formatTransactionDateDisplay(label) || label}
            </div>
            <ul className="indices-comparison-tooltip-list">
                {items.map((entry) => (
                    <li key={entry.dataKey} className="indices-comparison-tooltip-row">
                        <span
                            className="indices-comparison-tooltip-swatch"
                            style={{ backgroundColor: entry.color || entry.stroke }}
                        />
                        <span className="indices-comparison-tooltip-name">{entry.name}</span>
                        <span className="indices-comparison-tooltip-value">
                            {formatSignedPercent2(entry.value)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function LegendGroup({ title, items, selected, onToggle }) {
    if (!items.length) {
        return null;
    }

    return (
        <div className="indices-comparison-legend-group">
            <div className="indices-comparison-legend-group-title">{title}</div>
            <div className="indices-comparison-legend" role="group" aria-label={title}>
                {items.map((entry, idx) => {
                    const checked = selected.has(entry.symbol);
                    const color = colorForIndex(entry.symbol, idx);
                    const inputId = `index-legend-${entry.symbol}`;
                    return (
                        <label
                            key={entry.symbol}
                            htmlFor={inputId}
                            className={`indices-comparison-legend-item${checked ? '' : ' is-hidden'}`}
                        >
                            <input
                                id={inputId}
                                type="checkbox"
                                className="form-check-input indices-comparison-legend-check"
                                checked={checked}
                                onChange={() => onToggle(entry.symbol)}
                            />
                            <span
                                className="indices-comparison-legend-swatch"
                                style={{ backgroundColor: checked ? color : 'transparent', borderColor: color }}
                            />
                            {entry.name}
                        </label>
                    );
                })}
            </div>
        </div>
    );
}

export default function IndexComparisonChart({ comparison, indexes = [], loading }) {
    const { activePortfolioId } = usePortfolio();
    const series = comparison?.series || [];
    const chartData = useMemo(() => mergeComparisonSeries(series), [series]);
    const [selected, setSelected] = useState(() => new Set());
    const hydratedRef = useRef(false);

    const legendItems = useMemo(() => {
        const source = chartableIndexes(indexes.length > 0 ? indexes : series);
        return source.map((index) => ({
            symbol: index.symbol,
            name: index.name,
            tier: index.tier === 'sector' ? 'sector' : 'broad',
        }));
    }, [indexes, series]);

    const periodIndexes = useMemo(() => chartableIndexes(indexes), [indexes]);

    const availableSymbols = useMemo(
        () => legendItems.map((entry) => entry.symbol),
        [legendItems],
    );
    const availableKey = availableSymbols.join(',');
    const tierGroups = useMemo(() => groupIndexesByTier(legendItems), [legendItems]);

    useEffect(() => {
        hydratedRef.current = false;
        if (availableSymbols.length === 0) {
            setSelected(new Set());
            return;
        }

        const saved = loadIndexLegendSelection(activePortfolioId, availableSymbols);
        if (saved) {
            setSelected(saved);
        } else {
            setSelected(new Set(availableSymbols));
        }
        hydratedRef.current = true;
    }, [availableKey, activePortfolioId]);

    useEffect(() => {
        if (!hydratedRef.current || !activePortfolioId || availableSymbols.length === 0) {
            return;
        }
        saveIndexLegendSelection(activePortfolioId, selected);
    }, [selected, activePortfolioId, availableKey]);

    const toggleSeries = (symbol) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(symbol)) {
                next.delete(symbol);
            } else {
                next.add(symbol);
            }
            return next;
        });
    };

    const selectAll = () => setSelected(new Set(availableSymbols));
    const selectNone = () => setSelected(new Set());

    const allSelected = availableSymbols.length > 0
        && availableSymbols.every((symbol) => selected.has(symbol));
    const noneSelected = selected.size === 0;

    const manyPoints = chartData.length > 24;
    const visibleSeries = series.filter((entry) => selected.has(entry.symbol));

    return (
        <div className="card mb-4 indices-comparison-card">
            <div className="card-header py-2">
                <div className="fw-semibold">1-year relative performance</div>
                <div className="text-muted small">
                    Each line starts at 0% one year ago so indexes in different price ranges are comparable.
                    Use the legend to show broad and sector indexes of interest.
                    {comparison?.baseline_date ? ` Baseline: ${formatTransactionDateDisplay(comparison.baseline_date)}.` : ''}
                </div>
            </div>
            <div className="card-body">
                {loading ? (
                    <div className="text-muted small py-4 text-center">Loading comparison chart…</div>
                ) : chartData.length === 0 && indexes.length === 0 ? (
                    <div className="text-muted small py-4 text-center">
                        No index price history available yet. Run market index sync in Settings.
                    </div>
                ) : (
                    <>
                        <div className="indices-comparison-legend-panel card mb-3">
                            <div className="card-body py-2">
                                <div className="indices-comparison-legend-toolbar">
                                    <span className="fw-semibold small me-1">Indexes of interest</span>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-secondary"
                                        onClick={selectAll}
                                        disabled={allSelected}
                                    >
                                        Select all
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-secondary"
                                        onClick={selectNone}
                                        disabled={noneSelected}
                                    >
                                        Clear all
                                    </button>
                                    <span className="text-muted small">Saved for this portfolio</span>
                                </div>
                                <LegendGroup
                                    title="Broad market"
                                    items={tierGroups.broad}
                                    selected={selected}
                                    onToggle={toggleSeries}
                                />
                                <LegendGroup
                                    title="Sector"
                                    items={tierGroups.sector}
                                    selected={selected}
                                    onToggle={toggleSeries}
                                />
                            </div>
                        </div>

                        <div className="indices-comparison-chart-canvas">
                            {visibleSeries.length === 0 ? (
                                <div className="text-muted small py-4 text-center">
                                    Select at least one index in the legend to show the line chart.
                                </div>
                            ) : (
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart
                                        data={chartData}
                                        margin={{
                                            top: 8,
                                            right: 12,
                                            left: 4,
                                            bottom: manyPoints ? 40 : 20,
                                        }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis
                                            dataKey="date"
                                            tickFormatter={(value) => formatChartAxisDate(value) || value}
                                            tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                            stroke="var(--lido-border-strong)"
                                            minTickGap={36}
                                            angle={manyPoints ? -40 : 0}
                                            textAnchor={manyPoints ? 'end' : 'middle'}
                                            height={manyPoints ? 40 : 24}
                                        />
                                        <YAxis
                                            tickFormatter={(value) => formatSignedPercent2(value)}
                                            width={52}
                                            tick={{ fontSize: 11, fill: 'var(--lido-text-muted)' }}
                                            stroke="var(--lido-border-strong)"
                                        />
                                        <Tooltip
                                            content={<CompactComparisonTooltip />}
                                            wrapperStyle={{ zIndex: 20, outline: 'none' }}
                                            allowEscapeViewBox={{ x: true, y: true }}
                                        />
                                        {visibleSeries.map((entry, idx) => (
                                            <Line
                                                key={entry.symbol}
                                                type="monotone"
                                                dataKey={entry.symbol}
                                                name={entry.name}
                                                stroke={colorForIndex(entry.symbol, idx)}
                                                strokeWidth={2}
                                                dot={false}
                                                isAnimationActive={chartData.length <= 120}
                                            />
                                        ))}
                                    </LineChart>
                                </ResponsiveContainer>
                            )}
                        </div>

                        <IndexPeriodGrowthCharts
                            indexes={periodIndexes}
                            selectedSymbols={selected}
                        />
                    </>
                )}
            </div>
        </div>
    );
}
