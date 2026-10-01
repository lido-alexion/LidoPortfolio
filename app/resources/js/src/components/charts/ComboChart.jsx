import React, { useMemo, useState } from 'react';
import { Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { availableComboPresets, COMBO_CHART_PRESETS, cycleComboPreset } from './comboChartPresets';

export default function ComboChart({ rows = [], availability = {}, defaultPresetId = 'price-volume', height = 300, formatValue = (value) => value }) {
    const presets = useMemo(() => availableComboPresets(availability), [availability]);
    const [presetId, setPresetId] = useState(defaultPresetId);
    const preset = presets.find((item) => item.id === presetId) || cycleComboPreset(presets, presetId);
    const visibleRows = rows.filter((row) => preset?.series?.some((key) => row[key] != null));

    if (!preset) return <div className="text-muted small">No combo chart data available.</div>;

    return (
        <section className="lido-combo-chart card" aria-label="Combo chart">
            <div className="card-header d-flex align-items-center gap-2 py-2">
                <label className="visually-hidden" htmlFor="combo-chart-preset">Chart preset</label>
                <select id="combo-chart-preset" className="form-select form-select-sm" value={preset.id} onChange={(event) => setPresetId(event.target.value)}>
                    {presets.map((item) => <option key={item.id} value={item.id} disabled={!item.available}>{item.category}: {item.label}{!item.available ? ` — ${item.unavailableReason || 'Unavailable'}` : ''}</option>)}
                </select>
                <button type="button" className="btn btn-outline-secondary btn-sm" aria-label="Previous combo chart" onClick={() => setPresetId(cycleComboPreset(presets, preset.id, -1).id)}>‹</button>
                <button type="button" className="btn btn-outline-secondary btn-sm" aria-label="Next combo chart" onClick={() => setPresetId(cycleComboPreset(presets, preset.id, 1).id)}>›</button>
            </div>
            <div className="card-body" style={{ height }}>
                {!visibleRows.length ? <div className="text-muted small py-4 text-center">{preset.unavailableReason || 'Insufficient historical data for this preset.'}</div> : (
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart data={visibleRows} margin={{ top: 8, right: 48, left: 4, bottom: 24 }}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="date" />
                            <YAxis yAxisId="left" />
                            <YAxis yAxisId="right" orientation="right" />
                            <Tooltip formatter={(value, name) => [formatValue(value, name), name]} />
                            {preset.series.map((key, index) => preset.chartTypes[index] === 'bar'
                                ? <Bar key={key} yAxisId={preset.axes[key]} dataKey={key} name={key} fill={index ? '#6c757d' : '#0d6efd'} />
                                : <Line key={key} yAxisId={preset.axes[key]} type="monotone" dataKey={key} name={key} stroke={index ? '#fd7e14' : '#0d6efd'} dot={false} />)}
                        </ComposedChart>
                    </ResponsiveContainer>
                )}
            </div>
        </section>
    );
}
