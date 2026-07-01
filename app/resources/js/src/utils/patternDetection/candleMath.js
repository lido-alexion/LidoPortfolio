/**
 * OHLCV candle geometry helpers for pattern detection.
 * Index 0 is the oldest bar in the window; higher indices are newer.
 */

const TREND_LOOKBACK = 5;
const TREND_THRESHOLD = 0.03;

/** @param {{ open?: number, high?: number, low?: number, close: number }} bar */
export function barMetrics(bar) {
    const close = Number(bar.close);
    const open = bar.open != null && !Number.isNaN(Number(bar.open))
        ? Number(bar.open)
        : close;
    const high = bar.high != null && !Number.isNaN(Number(bar.high))
        ? Number(bar.high)
        : Math.max(open, close);
    const low = bar.low != null && !Number.isNaN(Number(bar.low))
        ? Number(bar.low)
        : Math.min(open, close);

    const range = high - low;
    const body = Math.abs(close - open);
    const bodySigned = close - open;
    const upperWick = high - Math.max(open, close);
    const lowerWick = Math.min(open, close) - low;
    const bodyRatio = range > 0 ? body / range : 0;
    const closePosition = range > 0 ? (close - low) / range : 0.5;

    return {
        open,
        close,
        high,
        low,
        range,
        body,
        bodySigned,
        upperWick,
        lowerWick,
        bodyRatio,
        closePosition,
    };
}

/** @param {ReturnType<typeof barMetrics>[]} metrics */
export function avgBody(metrics, endIdx, lookback = 10) {
    const start = Math.max(0, endIdx - lookback + 1);
    let sum = 0;
    let count = 0;
    for (let i = start; i <= endIdx; i += 1) {
        sum += metrics[i].body;
        count += 1;
    }
    return count > 0 ? sum / count : 0;
}

/** @param {Array<{ close: number }>} bars */
export function priorTrendPct(bars, endIdx, lookback = TREND_LOOKBACK) {
    if (endIdx < lookback) {
        return 0;
    }
    const startClose = bars[endIdx - lookback].close;
    const endClose = bars[endIdx - 1].close;
    if (!startClose || startClose <= 0) {
        return 0;
    }
    return (endClose - startClose) / startClose;
}

export function isDowntrend(bars, endIdx) {
    return priorTrendPct(bars, endIdx) <= -TREND_THRESHOLD;
}

export function isUptrend(bars, endIdx) {
    return priorTrendPct(bars, endIdx) >= TREND_THRESHOLD;
}

/** @param {Array<{ close: number }>} bars */
export function localPeaks(bars, minSeparation = 2) {
    const peaks = [];
    for (let i = 1; i < bars.length - 1; i += 1) {
        if (bars[i].close >= bars[i - 1].close && bars[i].close >= bars[i + 1].close) {
            if (peaks.length === 0 || i - peaks[peaks.length - 1] >= minSeparation) {
                peaks.push(i);
            }
        }
    }
    return peaks;
}

/** @param {Array<{ close: number }>} bars */
export function localTroughs(bars, minSeparation = 2) {
    const troughs = [];
    for (let i = 1; i < bars.length - 1; i += 1) {
        if (bars[i].close <= bars[i - 1].close && bars[i].close <= bars[i + 1].close) {
            if (troughs.length === 0 || i - troughs[troughs.length - 1] >= minSeparation) {
                troughs.push(i);
            }
        }
    }
    return troughs;
}

/** Simple linear regression slope for y values. */
export function linearSlope(values) {
    const n = values.length;
    if (n < 2) {
        return 0;
    }
    let sumX = 0;
    let sumY = 0;
    let sumXY = 0;
    let sumXX = 0;
    for (let i = 0; i < n; i += 1) {
        sumX += i;
        sumY += values[i];
        sumXY += i * values[i];
        sumXX += i * i;
    }
    const denom = n * sumXX - sumX * sumX;
    if (denom === 0) {
        return 0;
    }
    return (n * sumXY - sumX * sumY) / denom;
}
