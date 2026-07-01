/**
 * Multi-bar chart patterns with OHLCV-based definitions (for future detection).
 */

export const CHART_PATTERNS = [
    {
        id: 'cup_and_handle',
        name: 'Cup and Handle',
        category: 'bullish_continuation',
        minBars: 20,
        characteristics: [
            'Rounded U-shaped decline and recovery (the cup).',
            'Left and right rims at similar price levels.',
            'Shallow downward drift (handle) after the right rim.',
            'Volume often dries up in the handle, expands on breakout.',
        ],
        meaning: 'Bullish continuation — consolidation after advance; breakout above handle rim targets cup depth added to breakout.',
        mathRules: [
            'Partition window [0..n] into cup [0..c] and handle [c+1..n].',
            'Rim_left = peak(0, c/4), Rim_right = peak(3c/4, c).',
            '|Rim_left − Rim_right| / Rim_left ≤ ε (e.g. ε = 0.03).',
            'Cup_bottom = trough(c/4, 3c/4); depth = pct(Rim_left, Cup_bottom) typically 12–35%.',
            '∀i in cup interior: P[i].C ≥ Cup_bottom (rounded bottom, no sharp V).',
            'Handle_low = min(P[i].C) for i ∈ handle; pullback = pct(Rim_right, Handle_low) ∈ [0.05, 0.15].',
            'Handle duration < cup duration.',
            'Breakout: P[n].C > Rim_right × (1 + ε_break).',
        ],
    },
    {
        id: 'head_and_shoulders',
        name: 'Head and Shoulders',
        category: 'bearish_reversal',
        minBars: 15,
        characteristics: [
            'Three peaks: left shoulder, higher head, right shoulder.',
            'Neckline connects troughs between peaks.',
            'Volume often highest on left shoulder, lower on head.',
        ],
        meaning: 'Bearish reversal — loss of upward momentum; break below neckline targets head-to-neckline distance.',
        mathRules: [
            'Find three local peaks S1, H, S2 with indices i1 < i2 < i3.',
            'H = max(P[i2].C); S1, S2 ≈ equal: |P[i1].C − P[i3].C| / P[i1].C ≤ ε.',
            'P[i2].C > P[i1].C and P[i2].C > P[i3].C (head highest).',
            'Neckline N = line through trough(i1,i2) and trough(i2,i3) lows.',
            'Breakdown: P[t].C < N(t) for bar t after right shoulder.',
        ],
    },
    {
        id: 'inverse_head_and_shoulders',
        name: 'Inverse Head and Shoulders',
        category: 'bullish_reversal',
        minBars: 15,
        characteristics: [
            'Three troughs: left shoulder, lower head, right shoulder.',
            'Neckline across intervening peaks.',
        ],
        meaning: 'Bullish reversal — breakout above neckline targets head-to-neckline height.',
        mathRules: [
            'Three local troughs T1, H, T2 at indices i1 < i2 < i3.',
            'H = min(P[i2].C); T1, T2 similar depth within ε.',
            'P[i2].C < P[i1].C and P[i2].C < P[i3].C.',
            'Neckline through peak(i1,i2) and peak(i2,i3).',
            'Breakout: P[t].C > neckline(t).',
        ],
    },
    {
        id: 'double_top',
        name: 'Double Top',
        category: 'bearish_reversal',
        minBars: 10,
        characteristics: [
            'Two peaks at similar levels separated by a meaningful valley.',
            'Often “M” shape on the chart.',
        ],
        meaning: 'Bearish reversal when price breaks below the middle trough (neckline).',
        mathRules: [
            'Peaks P1, P2 at indices i < j with |P[i].C − P[j].C| / P[i].C ≤ ε.',
            'Valley V = min(P[k].C) for k ∈ (i, j); depth = pct(V, P1) ≥ 0.03.',
            'Breakdown: close below V after second peak.',
        ],
    },
    {
        id: 'double_bottom',
        name: 'Double Bottom',
        category: 'bullish_reversal',
        minBars: 10,
        characteristics: [
            'Two troughs at similar levels separated by a peak (“W” shape).',
        ],
        meaning: 'Bullish reversal on break above middle peak.',
        mathRules: [
            'Troughs T1, T2 with |P[i].C − P[j].C| / P[i].C ≤ ε.',
            'Peak between them: max close in (i,j); breakout above peak confirms.',
        ],
    },
    {
        id: 'ascending_triangle',
        name: 'Ascending Triangle',
        category: 'bullish_continuation',
        minBars: 10,
        characteristics: [
            'Flat horizontal resistance (equal highs).',
            'Rising higher lows (ascending support line).',
        ],
        meaning: 'Bullish bias — compression toward resistance; upside breakout common.',
        mathRules: [
            'Highs H_k with |slope(highs)| ≤ ε_flat over last m bars.',
            'Lows L_k with slope(lows) > 0 (rising support).',
            'Range width decreases: (H − L) shrinks over time.',
            'Breakout: P[t].C > resistance + ε.',
        ],
    },
    {
        id: 'descending_triangle',
        name: 'Descending Triangle',
        category: 'bearish_continuation',
        minBars: 10,
        characteristics: [
            'Flat support; lower highs (descending resistance).',
        ],
        meaning: 'Bearish bias — breakdown through support targets triangle height.',
        mathRules: [
            'Lows approximately flat: |slope(lows)| ≤ ε.',
            'Highs declining: slope(highs) < 0.',
            'Breakdown: P[t].C < support − ε.',
        ],
    },
    {
        id: 'symmetrical_triangle',
        name: 'Symmetrical Triangle',
        category: 'neutral',
        minBars: 10,
        characteristics: [
            'Lower highs and higher lows converging.',
            'Volume contracts toward apex.',
        ],
        meaning: 'Breakout direction decides trend; trade in direction of confirmed break with volume.',
        mathRules: [
            'slope(highs) < 0, slope(lows) > 0.',
            'Lines converge: width[i] = high_line(i) − low_line(i) strictly decreases.',
            'Breakout: close outside triangle boundary with volume > avg_volume.',
        ],
    },
    {
        id: 'bull_flag',
        name: 'Bull Flag',
        category: 'bullish_continuation',
        minBars: 8,
        characteristics: [
            'Sharp pole (strong rally) then tight parallel downward channel.',
            'Short consolidation duration vs pole.',
        ],
        meaning: 'Continuation — breakout above flag targets pole height added to breakout.',
        mathRules: [
            'Pole: pct(P[a], P[b]) ≥ pole_min (e.g. 8%) over short span [a,b].',
            'Flag [b+1..n]: slope(close) < 0, |slope| << pole slope.',
            'Parallel channel: slope(highs) ≈ slope(lows) in flag.',
            'Breakout: P[n].C > max(flag highs).',
        ],
    },
    {
        id: 'bear_flag',
        name: 'Bear Flag',
        category: 'bearish_continuation',
        minBars: 8,
        characteristics: [
            'Sharp decline (pole) then slight upward drift in a channel.',
        ],
        meaning: 'Bearish continuation on breakdown below flag.',
        mathRules: [
            'Pole: pct(P[a], P[b]) ≤ −pole_min.',
            'Flag: slope(close) > 0 but modest vs pole.',
            'Breakdown: P[n].C < min(flag lows).',
        ],
    },
    {
        id: 'rising_wedge',
        name: 'Rising Wedge',
        category: 'bearish_reversal',
        minBars: 10,
        characteristics: [
            'Both support and resistance rise, but support rises faster.',
            'Price range narrows upward.',
        ],
        meaning: 'Often bearish — upside momentum weakening; breakdown common.',
        mathRules: [
            'slope(support) > slope(resistance) > 0.',
            'Channel width decreases over time.',
            'Breakdown: close below support trendline.',
        ],
    },
    {
        id: 'falling_wedge',
        name: 'Falling Wedge',
        category: 'bullish_reversal',
        minBars: 10,
        characteristics: [
            'Both lines decline; resistance falls faster than support.',
            'Converging downward channel.',
        ],
        meaning: 'Often bullish reversal — breakout above upper trendline.',
        mathRules: [
            'slope(resistance) < slope(support) < 0.',
            'Width narrows.',
            'Breakout: close above resistance line.',
        ],
    },
];
