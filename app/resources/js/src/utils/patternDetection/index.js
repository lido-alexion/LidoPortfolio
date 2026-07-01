import { normalizeOhlcvRows } from '../ohlcvChartData.js';
import { CANDLESTICK_DETECTORS } from './detectCandlesticks.js';
import { CHART_DETECTORS } from './detectChartPatterns.js';
import {
    categoryClassName,
    categoryLabel,
    isActionableCategory,
    PATTERN_BY_ID,
} from './patternMeta.js';

const ALL_DETECTORS = [
    ...CANDLESTICK_DETECTORS.map((detect) => ({ detect, variant: 'candle' })),
    ...CHART_DETECTORS.map((detect) => ({ detect, variant: 'chart' })),
];

/**
 * Scan OHLCV rows for patterns completing on the last bar of the window.
 *
 * @param {Array<Record<string, unknown>>} rawRows
 * @param {{ actionableOnly?: boolean, windowBars?: number | null, includeChart?: boolean, includeCandle?: boolean }} [options]
 * @returns {Array<{ id: string, name: string, category: string, variant: string, barDate: string }>}
 */
export function scanOhlcv(rawRows, options = {}) {
    const {
        actionableOnly = false,
        windowBars = null,
        includeChart = true,
        includeCandle = true,
    } = options;

    const bars = normalizeOhlcvRows(rawRows);
    if (bars.length === 0) {
        return [];
    }

    const window = windowBars && bars.length > windowBars
        ? bars.slice(-windowBars)
        : bars;
    const endIdx = window.length - 1;
    const matches = [];

    for (const { detect, variant } of ALL_DETECTORS) {
        if (variant === 'chart' && !includeChart) {
            continue;
        }
        if (variant === 'candle' && !includeCandle) {
            continue;
        }

        const hit = detect(window, endIdx);
        if (!hit) {
            continue;
        }

        const meta = PATTERN_BY_ID[hit.id];
        if (!meta) {
            continue;
        }
        if (actionableOnly && !isActionableCategory(meta.category)) {
            continue;
        }

        matches.push({
            id: meta.id,
            name: meta.name,
            category: meta.category,
            variant: meta.variant,
            barDate: window[endIdx].date,
        });
    }

    return matches;
}

export {
    categoryClassName,
    categoryLabel,
    isActionableCategory,
    PATTERN_BY_ID,
};
