import { CHART_PATTERNS } from '../../data/chartPatterns.js';
import { CANDLESTICK_PATTERNS } from '../../data/candlestickPatterns.js';

/** @type {Record<string, { id: string, name: string, category: string, variant: 'candle' | 'chart' }>} */
export const PATTERN_BY_ID = {};

for (const pattern of CANDLESTICK_PATTERNS) {
    PATTERN_BY_ID[pattern.id] = {
        id: pattern.id,
        name: pattern.name,
        category: pattern.category,
        variant: 'candle',
    };
}

for (const pattern of CHART_PATTERNS) {
    PATTERN_BY_ID[pattern.id] = {
        id: pattern.id,
        name: pattern.name,
        category: pattern.category,
        variant: 'chart',
    };
}

/** Patterns that warrant attention on the dashboard (excludes neutral / indecision). */
export function isActionableCategory(category) {
    return category !== 'neutral';
}

export function categoryLabel(category) {
    const labels = {
        bullish: 'Bullish',
        bearish: 'Bearish',
        neutral: 'Neutral',
        bullish_continuation: 'Bullish continuation',
        bearish_continuation: 'Bearish continuation',
        bullish_reversal: 'Bullish reversal',
        bearish_reversal: 'Bearish reversal',
    };
    return labels[category] || category;
}

export function categoryClassName(category) {
    if (category === 'bearish' || category === 'bearish_continuation' || category === 'bearish_reversal') {
        return 'text-danger';
    }
    if (category === 'bullish' || category === 'bullish_continuation' || category === 'bullish_reversal') {
        return 'text-success';
    }
    return 'text-body';
}
