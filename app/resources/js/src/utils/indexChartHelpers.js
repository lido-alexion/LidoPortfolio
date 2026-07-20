const INDEX_LINE_COLORS = [
    '#0d6efd',
    '#dc3545',
    '#198754',
    '#fd7e14',
    '#6f42c1',
    '#20c997',
    '#d63384',
    '#ffc107',
    '#0dcaf0',
    '#6610f2',
    '#6c757d',
    '#e35d6a',
    '#2eb85c',
    '#0b7285',
    '#9c36b5',
    '#e67700',
    '#2b8a3e',
    '#c92a2a',
    '#364fc7',
    '#087f5b',
];

export function colorForIndex(symbol, index = 0) {
    const palette = INDEX_LINE_COLORS;
    if (!symbol) {
        return palette[index % palette.length];
    }
    let hash = 0;
    for (let i = 0; i < symbol.length; i += 1) {
        hash = (hash + symbol.charCodeAt(i) * (i + 1)) % palette.length;
    }
    return palette[hash];
}

export function mergeComparisonSeries(series) {
    if (!Array.isArray(series) || series.length === 0) {
        return [];
    }
    const byDate = new Map();
    series.forEach((entry) => {
        (entry.points || []).forEach((point) => {
            if (!byDate.has(point.date)) {
                byDate.set(point.date, { date: point.date });
            }
            byDate.get(point.date)[entry.symbol] = point.gain_percent;
        });
    });
    return Array.from(byDate.values()).sort((a, b) => a.date.localeCompare(b.date));
}

export function groupIndexesByExchange(indexes) {
    const groups = {};
    (indexes || []).forEach((index) => {
        const key = index.exchange || 'Other';
        if (!groups[key]) {
            groups[key] = [];
        }
        groups[key].push(index);
    });
    Object.keys(groups).forEach((key) => {
        groups[key].sort((a, b) => a.name.localeCompare(b.name));
    });
    return groups;
}

export function groupIndexesByTier(indexes) {
    const groups = { broad: [], sector: [], volatility: [] };
    (indexes || []).forEach((index) => {
        const tier = index.tier === 'sector'
            ? 'sector'
            : (index.tier === 'volatility' ? 'volatility' : 'broad');
        groups[tier].push(index);
    });
    groups.broad.sort((a, b) => a.name.localeCompare(b.name));
    groups.sector.sort((a, b) => a.name.localeCompare(b.name));
    groups.volatility.sort((a, b) => a.name.localeCompare(b.name));
    return groups;
}

/** Indexes that appear in comparison charts / shared legend (excludes VIX). */
export function chartableIndexes(indexes) {
    return (indexes || []).filter((index) => index.tier !== 'volatility');
}
