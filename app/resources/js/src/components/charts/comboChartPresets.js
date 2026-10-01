export const COMBO_CHART_PRESETS = Object.freeze([
    { id: 'price-volume', category: 'Market activity', label: 'Price + Volume', series: ['price', 'volume'], chartTypes: ['line', 'bar'], axes: { price: 'left', volume: 'right' } },
    { id: 'price-pe', category: 'Valuation', label: 'Price + P/E', series: ['price', 'pe'], chartTypes: ['line', 'line'], axes: { price: 'left', pe: 'right' } },
    { id: 'price-pb', category: 'Valuation', label: 'Price + P/B', series: ['price', 'pb'], chartTypes: ['line', 'line'], axes: { price: 'left', pb: 'right' } },
    { id: 'price-ps', category: 'Valuation', label: 'Price + P/S', series: ['price', 'ps'], chartTypes: ['line', 'line'], axes: { price: 'left', ps: 'right' } },
    { id: 'price-eps', category: 'Fundamentals', label: 'Price + EPS', series: ['price', 'eps'], chartTypes: ['line', 'line'], axes: { price: 'left', eps: 'right' } },
    { id: 'price-revenue', category: 'Fundamentals', label: 'Price + Revenue', series: ['price', 'revenue'], chartTypes: ['line', 'bar'], axes: { price: 'left', revenue: 'right' } },
    { id: 'price-net-profit', category: 'Fundamentals', label: 'Price + Net Profit', series: ['price', 'net_profit'], chartTypes: ['line', 'bar'], axes: { price: 'left', net_profit: 'right' } },
]);

export function availableComboPresets(availability = {}) {
    return COMBO_CHART_PRESETS.map((preset) => ({
        ...preset,
        available: availability[preset.id]?.available !== false,
        unavailableReason: availability[preset.id]?.reason || null,
    }));
}

export function cycleComboPreset(presets, currentId, direction = 1) {
    const available = presets.filter((preset) => preset.available !== false);
    if (!available.length) return null;
    const index = Math.max(0, available.findIndex((preset) => preset.id === currentId));
    return available[(index + direction + available.length) % available.length];
}
