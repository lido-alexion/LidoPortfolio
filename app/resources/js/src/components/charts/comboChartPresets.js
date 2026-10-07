export const COMBO_CHART_PRESETS = Object.freeze([
    { id: 'price-volume', category: 'Market activity', label: 'Price + Volume', series: ['price', 'volume'], chartTypes: ['line', 'bar'], axes: { price: 'left', volume: 'right' }, units: { price: '₹', volume: 'shares' } },
    { id: 'price-pe', category: 'Valuation', label: 'Price + P/E', series: ['price', 'pe'], chartTypes: ['line', 'line'], axes: { price: 'left', pe: 'right' }, units: { price: '₹', pe: 'x' } },
    { id: 'price-pb', category: 'Valuation', label: 'Price + P/B', series: ['price', 'pb'], chartTypes: ['line', 'line'], axes: { price: 'left', pb: 'right' }, units: { price: '₹', pb: 'x' } },
    { id: 'price-ps', category: 'Valuation', label: 'Price + P/S', series: ['price', 'ps'], chartTypes: ['line', 'line'], axes: { price: 'left', ps: 'right' }, units: { price: '₹', ps: 'x' } },
    { id: 'price-eps', category: 'Fundamentals', label: 'Price + EPS', series: ['price', 'eps'], chartTypes: ['line', 'bar'], axes: { price: 'left', eps: 'left' }, units: { price: '₹/share', eps: '₹/share' } },
    { id: 'price-revenue', category: 'Fundamentals', label: 'Price + Revenue', series: ['price', 'revenue'], chartTypes: ['line', 'bar'], axes: { price: 'left', revenue: 'right' }, units: { price: '₹', revenue: '₹' } },
    { id: 'price-net-profit', category: 'Fundamentals', label: 'Price + Net Profit', series: ['price', 'net_profit'], chartTypes: ['line', 'bar'], axes: { price: 'left', net_profit: 'right' }, units: { price: '₹', net_profit: '₹' } },
]);

export const DEFAULT_COMBO_PRESET_ID = 'price-volume';
export const COMBO_DEFAULT_SETTING_KEY = 'stock_details_combo_chart_default';

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
    const currentIndex = available.findIndex((preset) => preset.id === currentId);
    const index = currentIndex < 0 ? 0 : currentIndex;
    return available[((index + direction) % available.length + available.length) % available.length];
}

export function resolveComboDefault(explicitId, availability = {}) {
    const available = availableComboPresets(availability).filter((preset) => preset.available);
    if (explicitId && available.some((preset) => preset.id === explicitId)) return explicitId;
    if (available.some((preset) => preset.id === DEFAULT_COMBO_PRESET_ID)) return DEFAULT_COMBO_PRESET_ID;
    return available[0]?.id ?? null;
}

export function comboPresetAvailability({ prices = [], series = {} } = {}) {
    const availability = {};
    const priceRows = prices.filter((point) => {
        const date = String(point.date ?? point.price_date ?? '').slice(0, 10);
        const price = Number(point.price ?? point.close_price ?? point.close);
        return date && Number.isFinite(price);
    });
    const priceDates = priceRows.map((point) => String(point.date ?? point.price_date ?? '').slice(0, 10)).sort();
    const hasPriceTimeline = new Set(priceDates).size >= 2;
    const volumeCount = priceRows.filter((point) => point.volume != null && point.volume !== '' && Number.isFinite(Number(point.volume))).length;
    availability[DEFAULT_COMBO_PRESET_ID] = hasPriceTimeline && volumeCount >= 2
        ? { available: true }
        : { available: false, reason: 'Insufficient historical price and volume data (need 2 observations)' };

    for (const preset of COMBO_CHART_PRESETS.slice(1)) {
        const key = preset.series[1];
        const points = series[key]?.points ?? [];
        const inPriceWindow = points.filter((point) => {
            const date = String(point.as_of ?? point.period_end ?? '').slice(0, 10);
            return date && (!priceDates.length || (date >= priceDates[0] && date <= priceDates.at(-1)))
                && point.value != null && point.value !== '' && Number.isFinite(Number(point.value));
        }).length;
        availability[preset.id] = series[key]?.unavailableReason
            ? { available: false, reason: `Temporarily unavailable: ${series[key].unavailableReason}` }
            : hasPriceTimeline && inPriceWindow >= 4
                ? { available: true }
                : { available: false, reason: !hasPriceTimeline
                ? 'Insufficient historical price data for comparison'
                : `Insufficient historical ${key === 'net_profit' ? 'net profit' : key.toUpperCase()} data in the available price period (need 4 periods)` };
    }
    return availability;
}
