import {
    avgBody,
    barMetrics,
    isDowntrend,
    isUptrend,
} from './candleMath.js';

function metricsAt(bars, idx) {
    return barMetrics(bars[idx]);
}

function hammerShape(m) {
    if (m.range <= 0) {
        return false;
    }
    if (m.bodyRatio > 0.35) {
        return false;
    }
    if (m.body > 0 && m.lowerWick < 2 * m.body) {
        return false;
    }
    if (m.body > 0 && m.upperWick > 0.25 * m.body) {
        return false;
    }
    if (m.body === 0 && m.lowerWick < 0.5 * m.range) {
        return false;
    }
    return m.closePosition >= 0.60;
}

function invertedHammerShape(m) {
    if (m.range <= 0) {
        return false;
    }
    if (m.bodyRatio > 0.35) {
        return false;
    }
    if (m.body > 0 && m.upperWick < 2 * m.body) {
        return false;
    }
    if (m.body > 0 && m.lowerWick > 0.25 * m.body) {
        return false;
    }
    return m.closePosition <= 0.40;
}

function shootingStarShape(m) {
    if (m.range <= 0) {
        return false;
    }
    if (m.bodyRatio > 0.35) {
        return false;
    }
    if (m.body > 0 && m.upperWick < 2 * m.body) {
        return false;
    }
    if (m.body > 0 && m.lowerWick > 0.25 * m.body) {
        return false;
    }
    return m.closePosition <= 0.35;
}

/** @typedef {{ id: string }} PatternHit */

/** @type {Array<(bars: Array<Record<string, unknown>>, endIdx: number) => PatternHit | null>} */
export const CANDLESTICK_DETECTORS = [
    (bars, endIdx) => {
        const m = metricsAt(bars, endIdx);
        if (m.range <= 0 || m.bodyRatio > 0.10) {
            return null;
        }
        return { id: 'doji' };
    },
    (bars, endIdx) => {
        if (!hammerShape(metricsAt(bars, endIdx)) || !isDowntrend(bars, endIdx)) {
            return null;
        }
        return { id: 'hammer' };
    },
    (bars, endIdx) => {
        if (!invertedHammerShape(metricsAt(bars, endIdx)) || !isDowntrend(bars, endIdx)) {
            return null;
        }
        return { id: 'inverted_hammer' };
    },
    (bars, endIdx) => {
        if (!hammerShape(metricsAt(bars, endIdx)) || !isUptrend(bars, endIdx)) {
            return null;
        }
        return { id: 'hanging_man' };
    },
    (bars, endIdx) => {
        if (!shootingStarShape(metricsAt(bars, endIdx)) || !isUptrend(bars, endIdx)) {
            return null;
        }
        return { id: 'shooting_star' };
    },
    (bars, endIdx) => {
        const m = metricsAt(bars, endIdx);
        if (m.bodySigned <= 0 || m.bodyRatio < 0.90) {
            return null;
        }
        if (m.upperWick > 0.05 * m.range || m.lowerWick > 0.05 * m.range) {
            return null;
        }
        return { id: 'bullish_marubozu' };
    },
    (bars, endIdx) => {
        const m = metricsAt(bars, endIdx);
        if (m.bodySigned >= 0 || m.bodyRatio < 0.90) {
            return null;
        }
        if (m.upperWick > 0.05 * m.range || m.lowerWick > 0.05 * m.range) {
            return null;
        }
        return { id: 'bearish_marubozu' };
    },
    (bars, endIdx) => {
        const m = metricsAt(bars, endIdx);
        if (m.range <= 0 || m.bodyRatio > 0.30) {
            return null;
        }
        if (m.upperWick < 0.25 * m.range || m.lowerWick < 0.25 * m.range) {
            return null;
        }
        if (Math.abs(m.upperWick - m.lowerWick) > 0.20 * m.range) {
            return null;
        }
        return { id: 'spinning_top' };
    },
    (bars, endIdx) => {
        if (endIdx < 1) {
            return null;
        }
        const m0 = metricsAt(bars, endIdx - 1);
        const m1 = metricsAt(bars, endIdx);
        if (m0.bodySigned >= 0 || m1.bodySigned <= 0) {
            return null;
        }
        const min0 = Math.min(m0.open, m0.close);
        const max0 = Math.max(m0.open, m0.close);
        const min1 = Math.min(m1.open, m1.close);
        const max1 = Math.max(m1.open, m1.close);
        if (min1 > min0 || max1 < max0 || m1.body <= m0.body) {
            return null;
        }
        return { id: 'bullish_engulfing' };
    },
    (bars, endIdx) => {
        if (endIdx < 1) {
            return null;
        }
        const m0 = metricsAt(bars, endIdx - 1);
        const m1 = metricsAt(bars, endIdx);
        if (m0.bodySigned <= 0 || m1.bodySigned >= 0) {
            return null;
        }
        const min0 = Math.min(m0.open, m0.close);
        const max0 = Math.max(m0.open, m0.close);
        const min1 = Math.min(m1.open, m1.close);
        const max1 = Math.max(m1.open, m1.close);
        if (min1 > min0 || max1 < max0 || m1.body <= m0.body) {
            return null;
        }
        return { id: 'bearish_engulfing' };
    },
    (bars, endIdx) => {
        if (endIdx < 1) {
            return null;
        }
        const m0 = metricsAt(bars, endIdx - 1);
        const m1 = metricsAt(bars, endIdx);
        if (m0.bodySigned >= 0 || m1.bodySigned <= 0) {
            return null;
        }
        const min0 = Math.min(m0.open, m0.close);
        const max0 = Math.max(m0.open, m0.close);
        const min1 = Math.min(m1.open, m1.close);
        const max1 = Math.max(m1.open, m1.close);
        if (min1 <= min0 || max1 >= max0 || m1.body >= m0.body) {
            return null;
        }
        return { id: 'bullish_harami' };
    },
    (bars, endIdx) => {
        if (endIdx < 1) {
            return null;
        }
        const m0 = metricsAt(bars, endIdx - 1);
        const m1 = metricsAt(bars, endIdx);
        if (m0.bodySigned <= 0 || m1.bodySigned >= 0) {
            return null;
        }
        const min0 = Math.min(m0.open, m0.close);
        const max0 = Math.max(m0.open, m0.close);
        const min1 = Math.min(m1.open, m1.close);
        const max1 = Math.max(m1.open, m1.close);
        if (min1 <= min0 || max1 >= max0 || m1.body >= m0.body) {
            return null;
        }
        return { id: 'bearish_harami' };
    },
    (bars, endIdx) => {
        if (endIdx < 1 || !isDowntrend(bars, endIdx)) {
            return null;
        }
        const m0 = metricsAt(bars, endIdx - 1);
        const m1 = metricsAt(bars, endIdx);
        if (m0.bodySigned >= 0 || m1.bodySigned <= 0) {
            return null;
        }
        const midpoint = (m0.open + m0.close) / 2;
        if (m1.close <= midpoint || m1.close >= m0.open) {
            return null;
        }
        if (m1.open > m0.close) {
            return null;
        }
        return { id: 'piercing_line' };
    },
    (bars, endIdx) => {
        if (endIdx < 1 || !isUptrend(bars, endIdx)) {
            return null;
        }
        const m0 = metricsAt(bars, endIdx - 1);
        const m1 = metricsAt(bars, endIdx);
        if (m0.bodySigned <= 0 || m1.bodySigned >= 0) {
            return null;
        }
        const midpoint = (m0.open + m0.close) / 2;
        if (m1.close >= midpoint || m1.close <= m0.close) {
            return null;
        }
        if (m1.open < m0.close) {
            return null;
        }
        return { id: 'dark_cloud_cover' };
    },
    (bars, endIdx) => {
        if (endIdx < 2) {
            return null;
        }
        const allMetrics = bars.map((bar) => barMetrics(bar));
        const avg = avgBody(allMetrics, endIdx - 1);
        const m0 = allMetrics[endIdx - 2];
        const m1 = allMetrics[endIdx - 1];
        const m2 = allMetrics[endIdx];
        if (m0.bodySigned >= 0 || m0.body < avg) {
            return null;
        }
        if (m1.bodyRatio > 0.35 || m1.close >= m0.close) {
            return null;
        }
        if (m2.bodySigned <= 0) {
            return null;
        }
        const mid = (m0.open + m0.close) / 2;
        if (m2.close <= mid) {
            return null;
        }
        return { id: 'morning_star' };
    },
    (bars, endIdx) => {
        if (endIdx < 2) {
            return null;
        }
        const allMetrics = bars.map((bar) => barMetrics(bar));
        const avg = avgBody(allMetrics, endIdx - 1);
        const m0 = allMetrics[endIdx - 2];
        const m1 = allMetrics[endIdx - 1];
        const m2 = allMetrics[endIdx];
        if (m0.bodySigned <= 0 || m0.body < avg) {
            return null;
        }
        if (m1.bodyRatio > 0.35 || m1.close <= m0.close) {
            return null;
        }
        if (m2.bodySigned >= 0) {
            return null;
        }
        const mid = (m0.open + m0.close) / 2;
        if (m2.close >= mid) {
            return null;
        }
        return { id: 'evening_star' };
    },
    (bars, endIdx) => {
        if (endIdx < 2) {
            return null;
        }
        const allMetrics = bars.map((bar) => barMetrics(bar));
        const avg = avgBody(allMetrics, endIdx);
        for (let i = endIdx - 2; i <= endIdx; i += 1) {
            if (allMetrics[i].bodySigned <= 0 || allMetrics[i].body < 0.5 * avg) {
                return null;
            }
        }
        const c0 = bars[endIdx - 2].close;
        const c1 = bars[endIdx - 1].close;
        const c2 = bars[endIdx].close;
        if (!(c2 > c1 && c1 > c0)) {
            return null;
        }
        const m1 = allMetrics[endIdx - 1];
        const m0 = allMetrics[endIdx - 2];
        const m2 = allMetrics[endIdx];
        if (m1.open < m0.open || m1.open > m0.close) {
            return null;
        }
        if (m2.open < m1.open || m2.open > m1.close) {
            return null;
        }
        return { id: 'three_white_soldiers' };
    },
    (bars, endIdx) => {
        if (endIdx < 2) {
            return null;
        }
        const allMetrics = bars.map((bar) => barMetrics(bar));
        const avg = avgBody(allMetrics, endIdx);
        for (let i = endIdx - 2; i <= endIdx; i += 1) {
            if (allMetrics[i].bodySigned >= 0 || allMetrics[i].body < 0.5 * avg) {
                return null;
            }
        }
        const c0 = bars[endIdx - 2].close;
        const c1 = bars[endIdx - 1].close;
        const c2 = bars[endIdx].close;
        if (!(c2 < c1 && c1 < c0)) {
            return null;
        }
        const m1 = allMetrics[endIdx - 1];
        const m0 = allMetrics[endIdx - 2];
        const m2 = allMetrics[endIdx];
        if (m1.open > m0.open || m1.open < m0.close) {
            return null;
        }
        if (m2.open > m1.open || m2.open < m1.close) {
            return null;
        }
        return { id: 'three_black_crows' };
    },
];
