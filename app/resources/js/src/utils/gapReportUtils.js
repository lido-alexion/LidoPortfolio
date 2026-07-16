export function formatGapRangeList(ranges) {
    if (!Array.isArray(ranges) || ranges.length === 0) {
        return '—';
    }

    return ranges.map((range) => `${range.from} → ${range.to}`).join('; ');
}

export function formatProvidersTried(providers) {
    if (!Array.isArray(providers) || providers.length === 0) {
        return '—';
    }

    return providers.join(', ');
}

export function gapDays(gapFrom, gapTo) {
    if (!gapFrom || !gapTo) {
        return 0;
    }
    const from = new Date(`${gapFrom}T00:00:00`);
    const to = new Date(`${gapTo}T00:00:00`);
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) {
        return 0;
    }

    return Math.round((to - from) / 86400000);
}

export function gapRowKey(stockId, gapFrom, gapTo) {
    return `${stockId}:${gapFrom}:${gapTo}`;
}

/**
 * @param {Array<{symbol?: string, stock_id?: number, exchange?: string, ranges?: Array<{from: string, to: string}>}>} symbolsWithGaps
 * @param {Set<string>|string[]} ignoredKeys
 */
export function expandGapScanRows(symbolsWithGaps, ignoredKeys = []) {
    const ignored = ignoredKeys instanceof Set ? ignoredKeys : new Set(ignoredKeys);
    const rows = [];

    for (const symbolRow of symbolsWithGaps ?? []) {
        const ranges = symbolRow.ranges ?? [];
        for (const range of ranges) {
            const stockId = symbolRow.stock_id;
            const key = stockId ? gapRowKey(stockId, range.from, range.to) : null;
            rows.push({
                stock: symbolRow.symbol ?? '—',
                stock_id: stockId ?? null,
                exchange: symbolRow.exchange ?? '—',
                gap_start: range.from,
                gap_end: range.to,
                gap_days: gapDays(range.from, range.to),
                ignored: key ? ignored.has(key) : false,
                gap_key: key,
            });
        }
    }

    return rows;
}
