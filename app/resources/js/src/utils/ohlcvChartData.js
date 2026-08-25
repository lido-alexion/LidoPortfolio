import { normalizeToIsoDateString } from './transactionDate.js';
import {
    DEFAULT_SAMPLING,
    DEFAULT_TIME_RANGE,
    VOLUME_COLOR_DOWN,
    VOLUME_COLOR_NEUTRAL,
    VOLUME_COLOR_UP,
    samplingStepForValue,
} from '../components/charts/priceVolumeChartTypes.js';

function isoFromDateParts(year, month, day) {
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** @param {string} isoDate YYYY-MM-DD */
export function subtractCalendarRange(isoDate, timeRange) {
    if (!isoDate || timeRange === 'all') {
        return null;
    }

    const [year, month, day] = isoDate.split('-').map(Number);
    const date = new Date(year, month - 1, day);

    switch (timeRange) {
    case '1m':
        date.setMonth(date.getMonth() - 1);
        break;
    case '3m':
        date.setMonth(date.getMonth() - 3);
        break;
    case '6m':
        date.setMonth(date.getMonth() - 6);
        break;
    case '1y':
        date.setFullYear(date.getFullYear() - 1);
        break;
    case '5y':
        date.setFullYear(date.getFullYear() - 5);
        break;
    default:
        return null;
    }

    return isoFromDateParts(date.getFullYear(), date.getMonth() + 1, date.getDate());
}

/**
 * @param {Array<Record<string, unknown>>} rows
 * @returns {Array<{ date: string, close: number, volume: number, open?: number, high?: number, low?: number }>}
 */
export function normalizeOhlcvRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return [];
    }

    const normalized = [];

    for (const row of rows) {
        const date = normalizeToIsoDateString(row.price_date ?? row.date);
        const close = Number(row.close_price ?? row.close);
        const volumeRaw = row.volume;
        const volume = volumeRaw == null || volumeRaw === '' ? 0 : Number(volumeRaw);

        if (!date || Number.isNaN(close)) {
            continue;
        }

        normalized.push({
            date,
            close,
            volume: Number.isNaN(volume) ? 0 : volume,
            open: row.open_price != null ? Number(row.open_price) : undefined,
            high: row.high_price != null ? Number(row.high_price) : undefined,
            low: row.low_price != null ? Number(row.low_price) : undefined,
        });
    }

    normalized.sort((a, b) => a.date.localeCompare(b.date));

    return normalized;
}

/**
 * @param {ReturnType<typeof normalizeOhlcvRows>} rows
 * @param {string} timeRange
 */
export function filterByTimeRange(rows, timeRange = DEFAULT_TIME_RANGE) {
    if (!rows.length || timeRange === 'all') {
        return {
            filtered: rows,
            anchorDate: rows[rows.length - 1]?.date ?? null,
            cutoffDate: null,
            isClamped: false,
            earliestDate: rows[0]?.date ?? null,
        };
    }

    const anchorDate = rows[rows.length - 1].date;
    const earliestDate = rows[0].date;
    const cutoffDate = subtractCalendarRange(anchorDate, timeRange);
    const filtered = cutoffDate
        ? rows.filter((row) => row.date >= cutoffDate)
        : rows;

    const isClamped = Boolean(cutoffDate && earliestDate && earliestDate > cutoffDate);

    return {
        filtered,
        anchorDate,
        cutoffDate,
        isClamped,
        earliestDate,
    };
}

/**
 * @param {ReturnType<typeof normalizeOhlcvRows>} rows
 * @param {number} step
 */
export function sampleOhlcvBuckets(rows, step = 1) {
    if (!rows.length) {
        return [];
    }

    const bucketSize = Math.max(1, Math.floor(Number(step)) || 1);
    const buckets = [];

    for (let index = 0; index < rows.length; index += bucketSize) {
        const slice = rows.slice(index, index + bucketSize);
        const last = slice[slice.length - 1];
        const volume = slice.reduce((sum, row) => sum + (row.volume ?? 0), 0);

        buckets.push({
            date: last.date,
            close: last.close,
            volume,
            bucketSize: slice.length,
            bucketStartDate: slice[0].date,
        });
    }

    return buckets;
}

/**
 * @param {ReturnType<typeof sampleOhlcvBuckets>} buckets
 */
export function addVolumeColors(buckets) {
    return buckets.map((bucket, index) => {
        if (index === 0) {
            return {
                ...bucket,
                volumeColor: VOLUME_COLOR_NEUTRAL,
                changePercent: null,
            };
        }

        const previousClose = buckets[index - 1].close;
        const changePercent = previousClose > 0
            ? ((bucket.close - previousClose) / previousClose) * 100
            : null;
        const isUp = bucket.close >= previousClose;

        return {
            ...bucket,
            volumeColor: isUp ? VOLUME_COLOR_UP : VOLUME_COLOR_DOWN,
            changePercent,
        };
    });
}

/**
 * @param {Array<Record<string, unknown>>} rows
 * @param {{ timeRange?: string, sampling?: string }} options
 */
export function buildPriceVolumeChartData(
    rows,
    { timeRange = DEFAULT_TIME_RANGE, sampling = DEFAULT_SAMPLING } = {},
) {
    const normalized = normalizeOhlcvRows(rows);
    const rangeResult = filterByTimeRange(normalized, timeRange);
    const step = samplingStepForValue(sampling);
    const buckets = sampleOhlcvBuckets(rangeResult.filtered, step);
    const series = addVolumeColors(buckets);

    return {
        series,
        meta: {
            anchorDate: rangeResult.anchorDate,
            cutoffDate: rangeResult.cutoffDate,
            earliestDate: rangeResult.earliestDate,
            isClamped: rangeResult.isClamped,
            timeRange,
            sampling,
            samplingStep: step,
            rawCount: normalized.length,
            filteredCount: rangeResult.filtered.length,
            pointCount: series.length,
        },
    };
}

/** @param {ReturnType<typeof buildPriceVolumeChartData>['meta']} meta */
export function formatChartRangeClampHint(meta) {
    if (!meta?.isClamped || !meta.earliestDate || !meta.anchorDate) {
        return '';
    }

    return `Showing available data from ${meta.earliestDate} to ${meta.anchorDate} (less than selected range).`;
}

export function xAxisTickInterval(pointCount) {
    if (pointCount <= 12) {
        return 0;
    }
    if (pointCount <= 24) {
        return 1;
    }
    if (pointCount <= 60) {
        return Math.floor(pointCount / 12);
    }
    return Math.floor(pointCount / 10);
}
