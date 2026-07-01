import { CHART_PATTERNS } from '../data/chartPatterns.js';
import { CANDLESTICK_PATTERNS } from '../data/candlestickPatterns.js';

/** @param {string} [hash] */
export function normalizePatternHash(hash) {
    return (hash || '').replace(/^#/, '').trim();
}

/** @param {string} patternId */
export function patternGuideLink(patternId) {
    if (!patternId) {
        return '/patterns';
    }
    return `/patterns#${patternId}`;
}

/**
 * @param {string} patternId
 * @returns {'candle' | 'chart' | null}
 */
export function patternGuideSectionForId(patternId) {
    if (CANDLESTICK_PATTERNS.some((pattern) => pattern.id === patternId)) {
        return 'candle';
    }
    if (CHART_PATTERNS.some((pattern) => pattern.id === patternId)) {
        return 'chart';
    }
    return null;
}
