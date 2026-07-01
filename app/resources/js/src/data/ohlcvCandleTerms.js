/**
 * Shared OHLCV candle vocabulary for pattern definitions (detection-ready notation).
 * For bar i with open O, high H, low L, close C.
 */
export const OHLCV_CANDLE_TERMS = [
    {
        symbol: 'O[i], H[i], L[i], C[i]',
        meaning: 'Open, high, low, close of candle at index i (i = 0 is oldest in window).',
    },
    {
        symbol: 'body[i]',
        meaning: '|C[i] − O[i]| — absolute body size.',
    },
    {
        symbol: 'body_signed[i]',
        meaning: 'C[i] − O[i] — positive when bullish (close > open).',
    },
    {
        symbol: 'range[i]',
        meaning: 'H[i] − L[i]. Must be > 0 for ratio rules.',
    },
    {
        symbol: 'upper_wick[i]',
        meaning: 'H[i] − max(O[i], C[i]).',
    },
    {
        symbol: 'lower_wick[i]',
        meaning: 'min(O[i], C[i]) − L[i].',
    },
    {
        symbol: 'body_ratio[i]',
        meaning: 'body[i] / range[i] — small body when ≤ ~0.33.',
    },
    {
        symbol: 'close_position[i]',
        meaning: '(C[i] − L[i]) / range[i] — 0 = close at low, 1 = close at high.',
    },
    {
        symbol: 'avg_body, avg_range',
        meaning: 'Mean of body or range over a lookback window (e.g. last 20 bars).',
    },
    {
        symbol: 'P[i].C',
        meaning: 'Close of bar i in a multi-bar chart pattern (same as C[i]).',
    },
];

export const CHART_PATTERN_TERMS = [
    {
        symbol: 'P[i] = {O,H,L,C,V}',
        meaning: 'OHLCV tuple at bar index i along the timeline.',
    },
    {
        symbol: 'peak(L, R)',
        meaning: 'Local maximum of close (or high) between indices L and R.',
    },
    {
        symbol: 'trough(L, R)',
        meaning: 'Local minimum of close (or low) between indices L and R.',
    },
    {
        symbol: 'ε (epsilon)',
        meaning: 'Small tolerance, e.g. 1–3% of price, for “equal” highs/lows.',
    },
    {
        symbol: 'slope(L, R)',
        meaning: 'Linear regression slope of close from index L to R.',
    },
    {
        symbol: 'pct(a, b)',
        meaning: '100 × (b − a) / a — percentage change from a to b.',
    },
];
