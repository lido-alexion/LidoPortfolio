import {
    linearSlope,
    localPeaks,
    localTroughs,
} from './candleMath.js';

const EPS = 0.03;

/** @typedef {{ id: string }} PatternHit */

/**
 * Chart-pattern heuristics on the visible window; pattern must complete at endIdx.
 * @type {Array<(bars: Array<{ close: number, high?: number, low?: number }>, endIdx: number) => PatternHit | null>}
 */
export const CHART_DETECTORS = [
    (bars, endIdx) => {
        if (endIdx < 9) {
            return null;
        }
        const window = bars.slice(Math.max(0, endIdx - 29), endIdx + 1);
        const peaks = localPeaks(window, 3);
        if (peaks.length < 2) {
            return null;
        }
        const p1 = peaks[peaks.length - 2];
        const p2 = peaks[peaks.length - 1];
        const h1 = window[p1].close;
        const h2 = window[p2].close;
        if (Math.abs(h1 - h2) / h1 > EPS) {
            return null;
        }
        let valley = Infinity;
        for (let i = p1 + 1; i < p2; i += 1) {
            valley = Math.min(valley, window[i].close);
        }
        if (valley === Infinity || (h1 - valley) / h1 < 0.03) {
            return null;
        }
        if (window[window.length - 1].close > valley * 1.01) {
            return null;
        }
        return { id: 'double_top' };
    },
    (bars, endIdx) => {
        if (endIdx < 9) {
            return null;
        }
        const window = bars.slice(Math.max(0, endIdx - 29), endIdx + 1);
        const troughs = localTroughs(window, 3);
        if (troughs.length < 2) {
            return null;
        }
        const t1 = troughs[troughs.length - 2];
        const t2 = troughs[troughs.length - 1];
        const l1 = window[t1].close;
        const l2 = window[t2].close;
        if (Math.abs(l1 - l2) / l1 > EPS) {
            return null;
        }
        let peak = -Infinity;
        for (let i = t1 + 1; i < t2; i += 1) {
            peak = Math.max(peak, window[i].close);
        }
        if (peak === -Infinity || (peak - l1) / l1 < 0.03) {
            return null;
        }
        if (window[window.length - 1].close < peak * 0.99) {
            return null;
        }
        return { id: 'double_bottom' };
    },
    (bars, endIdx) => {
        if (endIdx < 14) {
            return null;
        }
        const window = bars.slice(endIdx - 14, endIdx + 1);
        const highs = window.map((b) => (b.high != null ? b.high : b.close));
        const lows = window.map((b) => (b.low != null ? b.low : b.close));
        const highSlope = linearSlope(highs);
        const lowSlope = linearSlope(lows);
        const highSpread = Math.max(...highs) - Math.min(...highs);
        const avgHigh = highs.reduce((a, b) => a + b, 0) / highs.length;
        if (avgHigh <= 0 || highSpread / avgHigh > 0.02) {
            return null;
        }
        if (lowSlope <= 0) {
            return null;
        }
        const widthStart = highs[0] - lows[0];
        const widthEnd = highs[highs.length - 1] - lows[lows.length - 1];
        if (widthEnd >= widthStart) {
            return null;
        }
        if (window[window.length - 1].close <= Math.max(...highs) * 0.995) {
            return null;
        }
        return { id: 'ascending_triangle' };
    },
    (bars, endIdx) => {
        if (endIdx < 14) {
            return null;
        }
        const window = bars.slice(endIdx - 14, endIdx + 1);
        const highs = window.map((b) => (b.high != null ? b.high : b.close));
        const lows = window.map((b) => (b.low != null ? b.low : b.close));
        const highSlope = linearSlope(highs);
        const lowSlope = linearSlope(lows);
        const lowSpread = Math.max(...lows) - Math.min(...lows);
        const avgLow = lows.reduce((a, b) => a + b, 0) / lows.length;
        if (avgLow <= 0 || lowSpread / avgLow > 0.02) {
            return null;
        }
        if (highSlope >= 0) {
            return null;
        }
        const widthStart = highs[0] - lows[0];
        const widthEnd = highs[highs.length - 1] - lows[lows.length - 1];
        if (widthEnd >= widthStart) {
            return null;
        }
        if (window[window.length - 1].close >= Math.min(...lows) * 1.005) {
            return null;
        }
        return { id: 'descending_triangle' };
    },
    (bars, endIdx) => {
        if (endIdx < 11) {
            return null;
        }
        const window = bars.slice(endIdx - 11, endIdx + 1);
        const poleStart = window[0].close;
        const poleEnd = window[3].close;
        if (poleStart <= 0 || (poleEnd - poleStart) / poleStart < 0.06) {
            return null;
        }
        const flag = window.slice(4);
        const flagSlope = linearSlope(flag.map((b) => b.close));
        if (flagSlope >= 0) {
            return null;
        }
        const flagHigh = Math.max(...flag.map((b) => (b.high != null ? b.high : b.close)));
        if (window[window.length - 1].close <= flagHigh) {
            return null;
        }
        return { id: 'bull_flag' };
    },
    (bars, endIdx) => {
        if (endIdx < 11) {
            return null;
        }
        const window = bars.slice(endIdx - 11, endIdx + 1);
        const poleStart = window[0].close;
        const poleEnd = window[3].close;
        if (poleStart <= 0 || (poleEnd - poleStart) / poleStart < -0.06) {
            return null;
        }
        const flag = window.slice(4);
        const flagSlope = linearSlope(flag.map((b) => b.close));
        if (flagSlope <= 0) {
            return null;
        }
        const flagLow = Math.min(...flag.map((b) => (b.low != null ? b.low : b.close)));
        if (window[window.length - 1].close >= flagLow) {
            return null;
        }
        return { id: 'bear_flag' };
    },
    (bars, endIdx) => {
        if (endIdx < 14) {
            return null;
        }
        const window = bars.slice(endIdx - 14, endIdx + 1);
        const peaks = localPeaks(window, 2);
        if (peaks.length < 3) {
            return null;
        }
        const [i1, i2, i3] = peaks.slice(-3);
        const h = window[i2].close;
        const s1 = window[i1].close;
        const s2 = window[i3].close;
        if (!(h > s1 && h > s2)) {
            return null;
        }
        if (Math.abs(s1 - s2) / s1 > EPS) {
            return null;
        }
        const neckline = Math.min(
            ...window.slice(i1, i2 + 1).map((b) => b.low ?? b.close),
            ...window.slice(i2, i3 + 1).map((b) => b.low ?? b.close),
        );
        if (window[window.length - 1].close > neckline) {
            return null;
        }
        return { id: 'head_and_shoulders' };
    },
    (bars, endIdx) => {
        if (endIdx < 14) {
            return null;
        }
        const window = bars.slice(endIdx - 14, endIdx + 1);
        const troughs = localTroughs(window, 2);
        if (troughs.length < 3) {
            return null;
        }
        const [i1, i2, i3] = troughs.slice(-3);
        const head = window[i2].close;
        const s1 = window[i1].close;
        const s2 = window[i3].close;
        if (!(head < s1 && head < s2)) {
            return null;
        }
        if (Math.abs(s1 - s2) / s1 > EPS) {
            return null;
        }
        const neckline = Math.max(
            ...window.slice(i1, i2 + 1).map((b) => b.high ?? b.close),
            ...window.slice(i2, i3 + 1).map((b) => b.high ?? b.close),
        );
        if (window[window.length - 1].close < neckline) {
            return null;
        }
        return { id: 'inverse_head_and_shoulders' };
    },
];
