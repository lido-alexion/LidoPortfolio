import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../api';
import MarketDepthTable from '../components/MarketDepthTable';
import { showToast } from '../toast';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../utils/transactionDate';

const VALUE_MODES = [
    { id: 'pct', label: '%' },
    { id: 'count', label: 'Count' },
];

const SERIES_COLORS = {
    rising: '#38bdf8',
    rs_55_positive: '#a78bfa',
    above_sma_20: '#fbbf24',
    above_sma_50: '#fb923c',
    above_sma_100: '#34d399',
    above_sma_200: '#22c55e',
};

function SegmentToggle({ options, value, onChange, ariaLabel }) {
    return (
        <div className="lido-segment-toggle-track" role="group" aria-label={ariaLabel}>
            {options.map((opt) => {
                const active = opt.id === value;
                return (
                    <button
                        key={opt.id}
                        type="button"
                        className={`lido-segment-toggle-btn${active ? ' is-active' : ''}`}
                        aria-pressed={active}
                        onClick={() => onChange(opt.id)}
                    >
                        {opt.label}
                    </button>
                );
            })}
        </div>
    );
}

export default function MarketDepthPage() {
    const [valueMode, setValueMode] = useState('pct');
    const [selectedDate, setSelectedDate] = useState('');
    const [availableDates, setAvailableDates] = useState([]);
    const [matrix, setMatrix] = useState(null);
    const [chartHistory, setChartHistory] = useState([]);
    const [chartSymbol, setChartSymbol] = useState('NIFTY50');
    const [enabledSeries, setEnabledSeries] = useState({});
    const [loading, setLoading] = useState(true);

    const load = useCallback(async (date, options = {}) => {
        const { updateChart = true } = options;
        setLoading(true);
        try {
            const params = {};
            if (date) {
                params.date = date;
            }
            const { data } = await api.get('/market-depth', { params });
            setAvailableDates(data.available_dates ?? []);
            setSelectedDate(data.as_of_date ?? '');
            setMatrix(data.matrix ?? null);
            if (updateChart) {
                setChartHistory(data.chart_history ?? data.nifty50_history ?? []);
                setChartSymbol(data.chart_symbol || 'NIFTY50');
            }
        } catch (e) {
            showToast(e?.response?.data?.message || e.message || 'Failed to load market depth', 'error');
            setMatrix(null);
            if (updateChart) {
                setChartHistory([]);
            }
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(selectedDate || undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- date changes are handled explicitly via onDateChange
    }, [load]);

    const onDateChange = (next) => {
        setSelectedDate(next);
        load(next || undefined, { updateChart: false });
    };

    const chartData = useMemo(() => {
        return (chartHistory ?? []).map((point) => {
            const row = { date: point.date, label: formatChartAxisDate(point.date) };
            const metrics = point.metrics ?? {};
            Object.keys(metrics).forEach((key) => {
                const m = metrics[key];
                row[key] = valueMode === 'count'
                    ? (m?.pass ?? null)
                    : (m?.pct ?? null);
            });
            return row;
        });
    }, [chartHistory, valueMode]);

    const seriesKeys = matrix?.columns?.map((c) => c.key) ?? Object.keys(SERIES_COLORS);
    const visibleSeriesKeys = useMemo(() => {
        if (!seriesKeys.length) {
            return [];
        }
        return seriesKeys.filter((k) => enabledSeries[k] !== false);
    }, [seriesKeys, enabledSeries]);

    useEffect(() => {
        if (!seriesKeys.length) {
            return;
        }
        setEnabledSeries((prev) => {
            const next = {};
            seriesKeys.forEach((key) => {
                next[key] = prev[key] !== false;
            });
            return next;
        });
    }, [seriesKeys]);

    const chartTitle = chartSymbol === 'SENSEX' ? 'Sensex' : 'Nifty 50';

    return (
        <div className="container-fluid py-3 lido-market-depth-page">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h1 className="h4 mb-0">Market Breadth</h1>
            </div>

            <div className="d-flex flex-wrap align-items-center gap-3 mb-3">
                <div className="d-flex align-items-center gap-2">
                    <span className="text-muted small">Values</span>
                    <SegmentToggle
                        options={VALUE_MODES}
                        value={valueMode}
                        onChange={setValueMode}
                        ariaLabel="Value mode"
                    />
                </div>
                <div className="d-flex align-items-center gap-2">
                    <label htmlFor="market-depth-date" className="text-muted small mb-0">Date</label>
                    <select
                        id="market-depth-date"
                        className="form-select form-select-sm"
                        style={{ width: 'auto', minWidth: '9rem' }}
                        value={selectedDate}
                        onChange={(e) => onDateChange(e.target.value)}
                        disabled={!availableDates.length}
                    >
                        {availableDates.length === 0 ? (
                            <option value="">No snapshots</option>
                        ) : (
                            availableDates.map((d) => (
                                <option key={d} value={d}>
                                    {formatTransactionDateDisplay(d) || d}
                                </option>
                            ))
                        )}
                    </select>
                </div>
            </div>

            {loading ? (
                <div className="text-muted py-4">Loading market breadth…</div>
            ) : (
                <>
                    {chartData.length > 0 ? (
                        <div className="mb-4">
                            <div className="text-muted small mb-2">
                                {chartTitle} — last {chartData.length} snapshot
                                {chartData.length === 1 ? '' : 's'}
                                {valueMode === 'pct' ? ' (%)' : ' (count)'}
                            </div>
                            <div className="d-flex flex-wrap gap-3 mb-2">
                                {seriesKeys.map((key) => {
                                    const col = matrix?.columns?.find((c) => c.key === key);
                                    const checked = enabledSeries[key] !== false;
                                    return (
                                        <label
                                            key={key}
                                            className="d-inline-flex align-items-center gap-2 small"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={(e) => {
                                                    const isChecked = e.target.checked;
                                                    setEnabledSeries((prev) => ({
                                                        ...prev,
                                                        [key]: isChecked,
                                                    }));
                                                }}
                                            />
                                            <span
                                                style={{
                                                    width: 10,
                                                    height: 10,
                                                    borderRadius: 999,
                                                    backgroundColor: SERIES_COLORS[key] || '#94a3b8',
                                                    display: 'inline-block',
                                                }}
                                            />
                                            <span>{col?.label ?? key}</span>
                                        </label>
                                    );
                                })}
                            </div>
                            <div className="lido-market-depth-chart" style={{ width: '100%', height: 260 }}>
                                <ResponsiveContainer>
                                    <LineChart data={chartData} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                                        <CartesianGrid stroke="var(--lido-border)" strokeDasharray="3 3" />
                                        <XAxis
                                            dataKey="label"
                                            tick={{ fill: 'var(--lido-text-muted)', fontSize: 11 }}
                                        />
                                        <YAxis
                                            tick={{ fill: 'var(--lido-text-muted)', fontSize: 11 }}
                                            width={40}
                                            domain={valueMode === 'pct' ? [0, 100] : ['auto', 'auto']}
                                        />
                                        <Tooltip
                                            contentStyle={{
                                                background: 'var(--lido-chart-tooltip-bg, var(--lido-bg-elevated))',
                                                border: '1px solid var(--lido-border)',
                                                color: 'var(--lido-text)',
                                            }}
                                        />
                                        {visibleSeriesKeys.map((key) => {
                                            const col = matrix?.columns?.find((c) => c.key === key);
                                            return (
                                                <Line
                                                    key={key}
                                                    type="monotone"
                                                    dataKey={key}
                                                    name={col?.label ?? key}
                                                    stroke={SERIES_COLORS[key] || '#94a3b8'}
                                                    strokeWidth={2}
                                                    dot={{ r: 3 }}
                                                    connectNulls
                                                />
                                            );
                                        })}
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    ) : null}

                    {matrix?.rows?.length ? (
                        <MarketDepthTable
                            data={matrix}
                            valueMode={valueMode}
                            bare
                            title=""
                            hideFooter={false}
                        />
                    ) : (
                        <div className="text-muted">
                            No NSE indexes in this snapshot
                            {availableDates.length === 0
                                ? '. Run cpanel-backfill-market-depth.php, then refresh.'
                                : '.'}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
