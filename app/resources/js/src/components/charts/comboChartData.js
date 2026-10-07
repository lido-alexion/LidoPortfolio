import { subtractCalendarRange } from '../../utils/ohlcvChartData.js';

const PRICE_DERIVED_VALUATIONS = new Set(['pe', 'pb', 'ps']);

const valueOf = (point) => {
    const value = Number(point?.value);
    return point?.value != null && point.value !== '' && Number.isFinite(value) ? value : null;
};

export function comboTooltipEntries(payload = [], preset, visible = {}) {
    if (!preset) return [];
    return preset.series.filter((key) => visible[key] !== false)
        .map((key) => ({ key, entry: payload.find((item) => item.dataKey === key) }))
        .filter(({ entry }) => entry?.value != null && Number.isFinite(Number(entry.value)));
}

/** Build sparse, exact-date series; fundamental observations are never forward-filled. */
export function alignComboChartData({ prices = [], fundamentals = {}, timeRange = 'all', sampling = '1d' } = {}) {
    const normalizedPrices = prices.map((row) => ({
        date: String(row.date ?? row.price_date ?? '').slice(0, 10),
        price: Number(row.price ?? row.close_price ?? row.close),
        volume: row.volume == null || row.volume === '' ? null : Number(row.volume),
    })).filter((row) => row.date && Number.isFinite(row.price)).sort((a, b) => a.date.localeCompare(b.date));
    const observations = {};
    for (const [key, value] of Object.entries(fundamentals)) {
        observations[key] = new Map((value?.points ?? []).map((point) => [String(point.as_of ?? point.period_end ?? '').slice(0, 10), valueOf(point)])
            .filter(([date, metric]) => date && metric !== null));
    }
    const dates = [...new Set([...normalizedPrices.map((row) => row.date), ...Object.values(observations).flatMap((map) => [...map.keys()])])].sort();
    const end = dates.at(-1);
    const cutoff = subtractCalendarRange(end, timeRange);
    const filtered = dates.filter((date) => !cutoff || date >= cutoff);
    const priceByDate = new Map(normalizedPrices.map((row) => [row.date, row]));
    let rows = filtered.map((date) => ({ date, price: priceByDate.get(date)?.price ?? null, volume: priceByDate.get(date)?.volume ?? null,
        ...Object.fromEntries(Object.entries(observations).map(([key, points]) => [key, points.get(date) ?? null])) }));
    if (sampling !== '1d') rows = rangeAndSamplingRows(rows, 'all', sampling);
    return rows;
}

export function rangeAndSamplingRows(rows, timeRange, sampling) {
    const dates = rows.map((row) => row.date).filter(Boolean).sort();
    const cutoff = subtractCalendarRange(dates.at(-1), timeRange);
    const subset = rows.filter((row) => !cutoff || row.date >= cutoff);
    if (sampling === '1d') return subset;
    const keyForDate = (date) => sampling === '1m'
        ? date.slice(0, 7)
        : `${date.slice(0, 4)}-${Math.ceil(Number(date.slice(5, 7)) / 3)}`;
    const groups = new Map();
    subset.forEach((row) => {
        const key = keyForDate(row.date);
        const group = groups.get(key) ?? { latestPrice: null, volume: 0, fundamentals: new Map() };
        if (row.price != null) {
            group.latestPrice = row;
            group.volume += Number.isFinite(Number(row.volume)) ? Number(row.volume) : 0;
        }
        const metrics = Object.fromEntries(Object.entries(row).filter(([field, value]) => !['date', 'price', 'volume'].includes(field) && !PRICE_DERIVED_VALUATIONS.has(field) && value != null));
        if (Object.keys(metrics).length) group.fundamentals.set(row.date, { ...(group.fundamentals.get(row.date) ?? { date: row.date }), ...metrics });
        groups.set(key, group);
    });
    return [...groups.values()].flatMap((group) => {
        const priceRow = group.latestPrice ? { ...group.latestPrice, volume: group.volume } : null;
        const observations = [...group.fundamentals.values()].map((observation) => observation.date === priceRow?.date
            ? { ...priceRow, ...observation }
            : { date: observation.date, price: null, volume: null, ...observation });
        if (priceRow && !observations.some((row) => row.date === priceRow.date)) observations.push(priceRow);
        return observations;
    }).sort((a, b) => a.date.localeCompare(b.date));
}
