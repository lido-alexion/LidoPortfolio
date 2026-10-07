import React, { useEffect, useMemo, useState } from 'react';
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { availableComboPresets, COMBO_CHART_PRESETS, cycleComboPreset, resolveComboDefault } from './comboChartPresets';
import { comboTooltipEntries, rangeAndSamplingRows } from './comboChartData';
import { formatTransactionDateDisplay } from '../../utils/transactionDate';

const RANGE_OPTIONS = [['all', 'All'], ['1m', '1M'], ['3m', '3M'], ['6m', '6M'], ['1y', '1Y'], ['5y', '5Y']];
const SAMPLING_OPTIONS = [['1d', 'Daily'], ['1m', 'Monthly'], ['1q', 'Quarterly']];
const COLORS = { price: '#0d6efd', volume: '#6c757d', pe: '#fd7e14', pb: '#20c997', ps: '#6f42c1', eps: '#dc3545', revenue: '#198754', net_profit: '#d63384' };

function ComboTooltip({ active, label, payload, preset, visible, formatValue }) {
    if (!active || !payload?.length) return null;
    const values = comboTooltipEntries(payload, preset, visible);
    if (!values.length) return null;
    return <div className="lido-combo-tooltip" role="status">
        <div className="fw-semibold mb-1">{formatTransactionDateDisplay(label) || label}</div>
        {values.map(({ key, entry }) => <div key={key} className="d-flex justify-content-between gap-3">
            <span><span aria-hidden="true" style={{ color: entry.color }}>● </span>{entry.name}</span>
            <strong>{formatValue(entry.value, key)}{preset.units[key] === 'x' ? '×' : ''}</strong>
        </div>)}
    </div>;
}

export default function ComboChart({ rows = [], availability = {}, defaultPresetId = 'price-volume', height = 320, onSetDefault, savingDefault = false, formatValue }) {
    const presets = useMemo(() => availableComboPresets(availability), [availability]);
    const [presetId, setPresetId] = useState(() => resolveComboDefault(defaultPresetId, availability) ?? 'price-volume');
    const [visible, setVisible] = useState({});
    const [range, setRange] = useState('all');
    const [sampling, setSampling] = useState('1d');
    useEffect(() => setPresetId(resolveComboDefault(defaultPresetId, availability) ?? 'price-volume'), [defaultPresetId, availability]);
    const preset = presets.find((item) => item.id === presetId && item.available) || presets.find((item) => item.id === resolveComboDefault(null, availability));
    const shownRows = useMemo(() => rangeAndSamplingRows(rows, range, sampling), [rows, range, sampling]);
    const activeSeries = preset?.series ?? [];
    const axisKeys = [...new Set(activeSeries.map((key) => preset.axes[key]))];
    const tooltipFormatter = formatValue ?? ((value, key) => formatComboValue(value, key, preset));
    const selectionChanged = preset?.id !== defaultPresetId;
    const next = (direction) => {
        const target = cycleComboPreset(presets, preset?.id, direction);
        if (target) setPresetId(target.id);
    };
    const toggle = (key) => setVisible((state) => ({ ...state, [key]: state[key] === false }));

    if (!preset) return <div className="text-muted small">No combo chart data available.</div>;
    return <section className="lido-combo-chart card" aria-label="Stock Details combo chart">
        <div className="card-header d-flex flex-wrap align-items-center gap-2 py-2">
            <label className="visually-hidden" htmlFor="combo-chart-preset">Combo chart preset</label>
            <select id="combo-chart-preset" className="form-select form-select-sm flex-grow-1" value={preset.id} onChange={(event) => setPresetId(event.target.value)}>
                {['Market activity', 'Valuation', 'Fundamentals'].map((category) => <optgroup key={category} label={category}>
                    {presets.filter((item) => item.category === category).map((item) => <option key={item.id} value={item.id} disabled={!item.available}>{item.label}{!item.available ? ` — ${item.unavailableReason || 'Unavailable'}` : ''}</option>)}
                </optgroup>)}
            </select>
            <button type="button" className="btn btn-outline-secondary btn-sm" aria-label="Previous combo chart" onClick={() => next(-1)}>‹</button>
            <button type="button" className="btn btn-outline-secondary btn-sm" aria-label="Next combo chart" onClick={() => next(1)}>›</button>
            {onSetDefault ? <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => onSetDefault(preset.id)} disabled={!selectionChanged || savingDefault}>
                {savingDefault ? 'Saving…' : selectionChanged ? 'Set as default' : 'Default chart'}
            </button> : null}
        </div>
        <div className="card-body pb-2">
            <div className="d-flex flex-wrap gap-2 mb-2" role="group" aria-label="Series visibility">
                {activeSeries.map((key) => {
                    const checked = visible[key] !== false;
                    const name = SERIES_LABELS[key];
                    return <label key={key} className={`btn btn-sm ${checked ? 'btn-outline-secondary' : 'btn-outline-secondary opacity-50'}`}>
                        <input className="form-check-input me-1" type="checkbox" checked={checked} onChange={() => toggle(key)} aria-label={`Show ${name}`} />
                        <span style={{ color: COLORS[key] }}>{name}</span>
                    </label>;
                })}
            </div>
            <div style={{ width: '100%', height, minHeight: 240 }}>
                {!shownRows.some((row) => activeSeries.some((key) => visible[key] !== false && row[key] != null))
                    ? <div className="text-muted small py-4 text-center">{presets.find((item) => item.id === presetId)?.unavailableReason || 'No observations for this range and frequency.'}</div>
                    : <ResponsiveContainer width="100%" height="100%"><ComposedChart data={shownRows} margin={{ top: 8, right: axisKeys.length > 1 ? 52 : 12, left: 8, bottom: 28 }}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis dataKey="date" tickFormatter={(date) => formatTransactionDateDisplay(date) || date} minTickGap={32} />
                        <YAxis yAxisId="left" tickFormatter={(value) => preset.units[activeSeries.find((key) => preset.axes[key] === 'left')]?.startsWith('₹') ? `₹${Number(value).toLocaleString()}` : value} />
                        {axisKeys.includes('right') ? <YAxis yAxisId="right" orientation="right" /> : null}
                        <Tooltip content={<ComboTooltip preset={preset} visible={visible} formatValue={tooltipFormatter} />} />
                        {activeSeries.map((key, index) => ({ key, index })).filter(({ key }) => visible[key] !== false).map(({ key, index }) => preset.chartTypes[index] === 'bar'
                            ? <Bar key={key} yAxisId={preset.axes[key]} dataKey={key} name={SERIES_LABELS[key]} fill={COLORS[key]} maxBarSize={18} />
                            : <Line key={key} yAxisId={preset.axes[key]} type="linear" connectNulls={key === 'price'} dataKey={key} name={SERIES_LABELS[key]} stroke={COLORS[key]} strokeWidth={2} dot={false} />)}
                    </ComposedChart></ResponsiveContainer>}
            </div>
            <div className="d-flex flex-wrap gap-3 mt-2">
                <div className="d-flex flex-wrap align-items-center gap-1" role="group" aria-label="Combo chart time range">{RANGE_OPTIONS.map(([value, label]) => <button key={value} type="button" className={`btn btn-sm ${range === value ? 'btn-primary' : 'btn-outline-primary'}`} aria-pressed={range === value} onClick={() => setRange(value)}>{label}</button>)}</div>
                <div className="d-flex flex-wrap align-items-center gap-1" role="group" aria-label="Combo chart sampling frequency">{SAMPLING_OPTIONS.map(([value, label]) => <button key={value} type="button" className={`btn btn-sm ${sampling === value ? 'btn-primary' : 'btn-outline-primary'}`} aria-pressed={sampling === value} onClick={() => setSampling(value)}>{label}</button>)}</div>
            </div>
            <p className="text-muted small mt-2 mb-0">Fundamental values stay on their reported observation dates. Monthly or quarterly sampling reduces price bars and sums volume without moving or filling fundamental observations.</p>
        </div>
    </section>;
}

const SERIES_LABELS = { price: 'Price', volume: 'Volume', pe: 'P/E', pb: 'P/B', ps: 'P/S', eps: 'EPS', revenue: 'Revenue', net_profit: 'Net profit' };
function formatComboValue(value, key, preset) {
    const formatted = Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
    if (preset.units[key] === 'shares') return `${formatted} shares`;
    if (preset.units[key]?.includes('/share')) return `₹${formatted}/share`;
    if (preset.units[key]?.startsWith('₹')) return `₹${formatted}`;
    return formatted;
}
