import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import SegmentToggle from '../components/SegmentToggle';
import { showToast } from '../toast';
import { formatChartAxisDate, formatTransactionDateDisplay } from '../utils/transactionDate';

const VALUE_MODES = [
    { value: 'pct', label: '%' },
    { value: 'count', label: 'Count' },
];

const SERIES_COLORS = {
    rising: '#38bdf8',
    rs_55_positive: '#a78bfa',
    above_sma_20: '#fbbf24',
    above_sma_50: '#fb923c',
    above_sma_100: '#34d399',
    above_sma_200: '#22c55e',
};

export default function MarketDepthPage() {
    const [valueMode, setValueMode] = useState('pct');
    const [selectedDate, setSelectedDate] = useState('');
    const [availableDates, setAvailableDates] = useState([]);
    const [datesConfirmed, setDatesConfirmed] = useState(false);
    const [confirmedSnapshot, setConfirmedSnapshot] = useState(null);
    const [chartHistory, setChartHistory] = useState([]);
    const [chartSymbol, setChartSymbol] = useState('NIFTY50');
    const [enabledSeries, setEnabledSeries] = useState({});
    const [readState, setReadState] = useState({ key: '', status: 'loading', error: '' });
    const matrixGenerationRef = useRef(0);
    const chartGenerationRef = useRef(0);
    const mountedRef = useRef(false);
    const requestedDateRef = useRef('');

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            matrixGenerationRef.current += 1;
            chartGenerationRef.current += 1;
        };
    }, []);

    const load = useCallback(async (date, options = {}) => {
        const { updateChart = true } = options;
        const requestedDate = date || '';
        requestedDateRef.current = requestedDate;
        const matrixGeneration = ++matrixGenerationRef.current;
        const chartGeneration = updateChart ? ++chartGenerationRef.current : null;
        setReadState({ key: requestedDate, status: 'loading', error: '' });
        try {
            const params = {};
            if (requestedDate) {
                params.date = requestedDate;
            }
            const { data } = await api.get('/market-depth', { params });
            const matrixIsUsable = data?.matrix === null || (
                data?.matrix
                && typeof data.matrix === 'object'
                && !Array.isArray(data.matrix)
                && Array.isArray(data.matrix.columns)
                && Array.isArray(data.matrix.rows)
            );
            if (!data || !Array.isArray(data.available_dates) || !Object.prototype.hasOwnProperty.call(data, 'matrix') || !matrixIsUsable) {
                throw new Error('Invalid market depth response');
            }
            const latestDate = String(data.as_of_date ?? requestedDate);
            const matrixIsCurrent = mountedRef.current
                && matrixGenerationRef.current === matrixGeneration
                && requestedDateRef.current === requestedDate;
            if (matrixIsCurrent) {
                const selected = data.available_dates.includes(latestDate) || requestedDate ? latestDate : '';
                setAvailableDates(data.available_dates);
                setDatesConfirmed(true);
                setConfirmedSnapshot({ date: selected, matrix: data.matrix });
                requestedDateRef.current = selected;
                setSelectedDate(selected);
                setReadState({ key: selected, status: 'confirmed', error: '' });
            }
            const responseChart = data.chart_history ?? data.nifty50_history ?? [];
            if (updateChart && Array.isArray(responseChart) && mountedRef.current && chartGenerationRef.current === chartGeneration) {
                setChartHistory(responseChart);
                setChartSymbol(data.chart_symbol || 'NIFTY50');
            }
        } catch (e) {
            const isCurrent = mountedRef.current
                && matrixGenerationRef.current === matrixGeneration
                && requestedDateRef.current === requestedDate;
            if (isCurrent) {
                setReadState({ key: requestedDate, status: 'error', error: 'Market breadth could not be loaded.' });
                showToast(e?.response?.data?.message || e.message || 'Failed to load market depth', 'error');
            }
        }
    }, []);

    useEffect(() => {
        load(selectedDate || undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- date changes are handled explicitly via onDateChange
    }, [load]);

    const onDateChange = (next) => {
        requestedDateRef.current = next;
        setSelectedDate(next);
        load(next || undefined, { updateChart: false });
    };

    const currentSnapshot = confirmedSnapshot?.date === selectedDate ? confirmedSnapshot : null;
    const currentReadState = readState.key === selectedDate
        ? readState
        : { key: selectedDate, status: 'loading', error: '' };
    const loading = currentReadState.status === 'loading';
    const loadError = currentReadState.status === 'error' ? currentReadState.error : '';
    const staleSnapshot = Boolean(currentSnapshot && (loading || loadError));

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

    const matrix = currentSnapshot?.matrix ?? null;
    const seriesKeys = useMemo(() => matrix?.columns?.map((c) => c.key) ?? Object.keys(SERIES_COLORS), [matrix]);
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
                <h2 className="h4 mb-0">Market Breadth</h2>
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
                            <option value="">{datesConfirmed ? 'No snapshots' : (loading ? 'Loading snapshots…' : 'Snapshots unavailable')}</option>
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

            {loadError ? <div className="alert alert-danger" role="alert"><p>{loadError}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={() => load(selectedDate || undefined)}>Retry market breadth</button></div> : null}
            {loading && !currentSnapshot ? <div className="text-muted py-4" role="status">Loading market breadth…</div> : null}
            {!datesConfirmed && !loading && !loadError ? <div className="text-muted py-4" role="status">Waiting for a confirmed snapshot list.</div> : null}
            {staleSnapshot ? <div className="alert alert-warning small" role="status">Showing the last confirmed matrix for this date; it may be out of date.</div> : null}
            {loadError && !currentSnapshot ? <div className="text-muted py-4" role="status">No confirmed matrix is available for the selected date.</div> : null}
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
                    ) : currentSnapshot && !loadError ? (
                        <div className="text-muted">
                            No NSE indexes in this snapshot
                            {availableDates.length === 0
                                ? '. Ask an administrator to review source coverage.'
                                : '.'}
                        </div>
                    ) : null}
            </>
        </div>
    );
}
