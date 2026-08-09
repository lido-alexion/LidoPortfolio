import React, { useEffect, useMemo, useState } from 'react';
import api from '../../api';

const DEFINITION_PREVIEW_CHARS = 50;

// Plain-language definitions (max 600 chars each) with formulas/examples where helpful.
const INDICATOR_DEFINITIONS = {
    close: 'The last traded price of the most recent cached session. Most other indicators (SMA, EMA, RSI…) are computed from closes. Example: close > sma(200) keeps stocks trading above their 200-session average.',
    open: 'The first traded price of the most recent session. Compare with close for the day\u2019s direction: close > open means the latest bar closed higher than it opened (a green candle).',
    high: 'The highest traded price of the most recent session. Example: a close equal to high means the stock finished at its intraday peak — often a sign of strong buying into the close.',
    low: 'The lowest traded price of the most recent session. Example: a close equal to low means the stock finished at its intraday bottom — often a sign of selling pressure into the close.',
    volume: 'Number of shares traded in the most recent session. Rising prices on heavy volume are more trustworthy than on thin volume. Example: volume > 2 × volume_sma(20) (weight 2) flags unusual activity.',
    change_pct: 'Percent change of the latest close versus the close N sessions ago. Formula: ((close_today − close_N_ago) / close_N_ago) × 100. Example: change_pct(period 1) > 5 finds stocks that rose more than 5% in a single session.',
    high_n: 'Highest high over the last N sessions. Example: close ≥ high_n(20) finds 20-session breakouts — price at or above the highest high of the last 20 bars.',
    low_n: 'Lowest low over the last N sessions. Example: close ≤ low_n(20) finds 20-session breakdowns — price at or below the lowest low of the last 20 bars.',
    high_52w: 'Highest high over up to the last 252 trading sessions (≈52 weeks); shorter history uses all available bars. Example: close ≥ 0.95 × high_52w (weight 0.95) finds stocks within 5% of their 52-week high.',
    low_52w: 'Lowest low over up to the last 252 trading sessions (≈52 weeks); shorter history uses all available bars. Example: close ≤ 1.1 × low_52w (weight 1.1) finds stocks within 10% of their 52-week low.',
    range_pct: 'Intraday swing of the latest bar as a percent of its close. Formula: ((high − low) / close) × 100. Example: range_pct > 5 finds stocks that moved more than 5% within the latest session; with left entity Nifty 50 you can compare a stock\u2019s range against the index\u2019s.',
    sma: 'Simple Moving Average: the plain average of the last N closes, all weighted equally. Formula: (c1 + c2 + … + cN) / N. It smooths day-to-day noise to reveal the trend. Example: sma(50) > sma(200) is the classic golden-cross style uptrend filter.',
    ema: 'Exponential Moving Average: like an SMA but recent closes count more (multiplier 2 / (N+1)), so it reacts faster to fresh prices. Seeded with the SMA of the first N closes. Example: close > ema(21) keeps stocks above their 21-session EMA.',
    price_vs_sma_pct: 'How far the latest close sits from its N-session SMA, in percent. Formula: ((close − SMA) / SMA) × 100. Positive = above the average. Example: price_vs_sma_pct(200) > 0 combined with < 10 finds stocks above — but not overextended from — the 200 SMA.',
    price_vs_ema_pct: 'How far the latest close sits from its N-session EMA, in percent. Formula: ((close − EMA) / EMA) × 100. Positive = above the average. Example: price_vs_ema_pct(50) < −10 finds stocks stretched 10%+ below their 50 EMA (possible mean-reversion candidates).',
    sma_spread_pct: 'Gap between a fast and a slow SMA, in percent of the slow one. Formula: ((SMA_fast − SMA_slow) / SMA_slow) × 100. Positive = fast above slow (uptrend). Example: sma_spread_pct(20, 50) > 2 requires the 20 SMA to sit at least 2% above the 50 SMA.',
    ema_spread_pct: 'Gap between a fast and a slow EMA, in percent of the slow one. Formula: ((EMA_fast − EMA_slow) / EMA_slow) × 100. With defaults 12/26 it is a percentage cousin of the MACD line. Example: ema_spread_pct(12, 26) > 0 keeps stocks with bullish EMA alignment.',
    rsi: 'Relative Strength Index (0–100): momentum from average gains vs average losses over N sessions (Wilder smoothing). Formula: 100 − 100 / (1 + RS), where RS = avg gain / avg loss. Above 70 is commonly read as overbought, below 30 as oversold. Example: rsi(14) < 30 finds oversold stocks.',
    roc: 'Rate of Change: percent move of the close over the last N sessions (same math as % Change). Formula: ((close − close_N_ago) / close_N_ago) × 100. Example: roc(12) > 0 keeps stocks with positive 12-session momentum.',
    stoch_k: 'Stochastic %K (0–100): where the latest close sits inside the high–low range of the last N sessions. Formula: ((close − lowest low) / (highest high − lowest low)) × 100. Near 100 = closing at the top of its range. Example: stoch_k(14) < 20 finds oversold stocks.',
    stoch_d: 'Stochastic %D: the smoothed signal line of %K — a simple average of the last “Smooth” %K values (typically 3). %K crossing above %D is the classic bullish trigger; as levels, stoch_d(14, 3) < 20 works as an oversold confirmation.',
    macd: 'MACD line: fast EMA minus slow EMA (default 12 − 26), in price units. Above zero means the short-term average is above the long-term one (bullish momentum). Example: macd(12, 26) > 0.',
    macd_signal: 'MACD signal line: an EMA (default 9) of the MACD line itself. MACD crossing above its signal is the classic bullish crossover — express it here as macd > macd_signal, or simply macd_hist > 0.',
    macd_hist: 'MACD histogram: MACD line minus its signal line. Positive and growing = strengthening bullish momentum; negative = bearish. Example: macd_hist(12, 26, 9) > 0 keeps stocks where MACD sits above its signal line.',
    atr: 'Average True Range: the average size of the “true” daily move over N sessions (Wilder smoothing). True range = max(high − low, |high − prev close|, |low − prev close|), so gaps count. In price units. Example: atr(14) < 0.03 × close (weight 0.03) caps typical daily swing near 3% of price.',
    bb_mid: 'Bollinger middle band: simply the N-session SMA of closes (default 20) — the centre line the upper and lower bands are drawn around. Example: close > bb_mid(20) means price is in the upper half of the bands.',
    bb_upper: 'Bollinger upper band: middle band + Mult × standard deviation of the last N closes (defaults 20, 2). Price touching or exceeding it suggests a statistically stretched move. Example: close ≥ bb_upper(20, 2) flags upper-band breakouts.',
    bb_lower: 'Bollinger lower band: middle band − Mult × standard deviation of the last N closes (defaults 20, 2). Price at or below it suggests a statistically stretched decline. Example: close ≤ bb_lower(20, 2) flags lower-band breakdowns.',
    bb_pct_b: 'Bollinger %B: position of the close inside the bands. Formula: (close − lower band) / (upper band − lower band). 1 = at the upper band, 0 = at the lower band, 0.5 = at the middle; values outside 0–1 mean price is outside the bands. Example: bb_pct_b(20, 2) < 0 finds closes below the lower band.',
    bb_width_pct: 'Bollinger band width as a percent of the middle band. Formula: ((upper − lower) / middle) × 100. A small width is a “squeeze” — low volatility that often precedes a breakout. Example: bb_width_pct(20, 2) < 6 screens for tight squeezes.',
    volume_sma: 'Average volume over the last N sessions (simple average). This is the baseline for judging whether today\u2019s turnover is unusual. Example: volume > 2 × volume_sma(20) (weight 2) finds volume spikes.',
    volume_ratio: 'Today\u2019s volume divided by its N-session average. 1 = normal activity, 2 = twice normal. Formula: volume / volume_sma(N). Example: volume_ratio(20) > 3 flags heavy-turnover sessions that often accompany news or breakouts.',
};

// Investopedia pages for the well-known indicators (shown as a hover info link).
const INVESTOPEDIA_LINKS = {
    close: 'https://www.investopedia.com/terms/c/closingprice.asp',
    open: 'https://www.investopedia.com/terms/o/openingprice.asp',
    volume: 'https://www.investopedia.com/terms/v/volume.asp',
    high_52w: 'https://www.investopedia.com/terms/1/52weekhighlow.asp',
    low_52w: 'https://www.investopedia.com/terms/1/52weekhighlow.asp',
    sma: 'https://www.investopedia.com/terms/s/sma.asp',
    ema: 'https://www.investopedia.com/terms/e/ema.asp',
    rsi: 'https://www.investopedia.com/terms/r/rsi.asp',
    roc: 'https://www.investopedia.com/terms/p/pricerateofchange.asp',
    stoch_k: 'https://www.investopedia.com/terms/s/stochasticoscillator.asp',
    stoch_d: 'https://www.investopedia.com/terms/s/stochasticoscillator.asp',
    macd: 'https://www.investopedia.com/terms/m/macd.asp',
    macd_signal: 'https://www.investopedia.com/terms/m/macd.asp',
    macd_hist: 'https://www.investopedia.com/terms/m/macd.asp',
    atr: 'https://www.investopedia.com/terms/a/atr.asp',
    bb_mid: 'https://www.investopedia.com/terms/b/bollingerbands.asp',
    bb_upper: 'https://www.investopedia.com/terms/b/bollingerbands.asp',
    bb_lower: 'https://www.investopedia.com/terms/b/bollingerbands.asp',
    bb_pct_b: 'https://www.investopedia.com/terms/b/bollingerbands.asp',
    bb_width_pct: 'https://www.investopedia.com/terms/b/bollingerbands.asp',
};

function IndicatorDefinition({ text }) {
    const [expanded, setExpanded] = useState(false);
    if (!text) {
        return <span className="text-muted">—</span>;
    }
    if (text.length <= DEFINITION_PREVIEW_CHARS) {
        return <span>{text}</span>;
    }
    return (
        <span>
            {expanded ? text : `${text.slice(0, DEFINITION_PREVIEW_CHARS)}...`}
            {' '}
            <button
                type="button"
                className="btn btn-link btn-sm p-0 align-baseline"
                onClick={() => setExpanded((v) => !v)}
                aria-expanded={expanded}
            >
                {expanded ? 'Less' : 'More'}
            </button>
        </span>
    );
}

const INDICATOR_GROUPS = [
    {
        title: 'Price & volume (bar fields)',
        ids: ['close', 'open', 'high', 'low', 'volume', 'change_pct', 'high_n', 'low_n', 'high_52w', 'low_52w', 'range_pct'],
        blurb: 'Latest OHLCV values, session change %, rolling highs/lows, 52-week high/low (up to 252 sessions; shorter history uses all available bars), and intraday range on the latest bar.',
    },
    {
        title: 'Moving averages & trend',
        ids: ['sma', 'ema', 'price_vs_sma_pct', 'price_vs_ema_pct', 'sma_spread_pct', 'ema_spread_pct'],
        blurb: 'Simple and exponential moving averages, price distance from MA, and fast-vs-slow MA spread.',
    },
    {
        title: 'Momentum',
        ids: ['rsi', 'roc', 'stoch_k', 'stoch_d', 'macd', 'macd_signal', 'macd_hist'],
        blurb: 'RSI, rate of change, stochastic, and MACD line/signal/histogram.',
    },
    {
        title: 'Volatility',
        ids: ['atr', 'bb_mid', 'bb_upper', 'bb_lower', 'bb_pct_b', 'bb_width_pct'],
        blurb: 'Average True Range and Bollinger band middle, bands, %B position, and band width.',
    },
    {
        title: 'Volume confirmation',
        ids: ['volume_sma', 'volume_ratio'],
        blurb: 'Volume moving average and ratio of today’s volume to its SMA.',
    },
];

function formatParams(params) {
    if (!params?.length) {
        return '';
    }
    return params.map((p) => `${p.label} (${p.default})`).join(', ');
}

export default function ScreenerGuideTab() {
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;
        api.get('/screeners/meta', { skipErrorToast: true })
            .then((res) => {
                if (!cancelled) {
                    setMeta(res.data?.data ?? null);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, []);

    const indicatorById = useMemo(() => {
        const map = {};
        (meta?.indicators || []).forEach((ind) => {
            map[ind.id] = ind;
        });
        return map;
    }, [meta]);

    if (loading) {
        return <p className="text-muted">Loading guide…</p>;
    }

    return (
        <div className="card">
            <div className="card-body">
                <h2 className="h5 mb-3">Screener guide</h2>
                <p className="text-muted">
                    Screeners filter stocks using cached OHLCV from your portfolio database — no live fetch during a run.
                    Build nested <strong>AND</strong>/<strong>OR</strong> groups, pick a scope (holdings, watchlist,
                    all equities, or index constituents), and compare indicators to constants or other indicators.
                </p>

                <h3 className="h6 mt-4">Scopes</h3>
                <ul className="small mb-4">
                    {(meta?.scopes || []).map((s) => (
                        <li key={s.id}>
                            <strong>{s.label}</strong>
                            {s.id === 'index' ? (
                                <span className="text-muted">
                                    {' '}
                                    — NSE broad and sector indexes with a constituents cache (e.g. Nifty 50, Nifty Bank).
                                    BSE indexes and India VIX are not available.
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ul>

                <h3 className="h6">Operators</h3>
                <p className="small text-muted">
                    {(meta?.operators || []).map((op) => op.label).join(' · ')}
                    . Next to the operator you can set a <strong>weight factor</strong> (default 1) so the comparison is
                    {' '}
                    <em>left</em>
                    {' '}
                    vs
                    {' '}
                    <em>weight × right</em>
                    {' '}
                    (for example SMA(5) &gt; 0.5 × SMA(20)).
                </p>

                <h3 className="h6 mt-4">Left entity (index comparisons)</h3>
                <p className="small text-muted">
                    Each condition&rsquo;s <strong>left side</strong> has an entity dropdown (default <strong>Stock</strong>).
                    Pick an index (
                    {(meta?.left_entities || []).filter((e) => e.id !== 'stock').map((e) => e.label).join(', ') || 'Nifty 50, Sensex, …'}
                    ) to compute the left indicator on that index&rsquo;s OHLCV instead of the scanned stock.
                    The <strong>right side always evaluates on the stock</strong>, and the result set is always stocks —
                    for example <em>Nifty 50 range_pct &lt; range_pct</em> finds stocks whose intraday range beats the index&rsquo;s.
                    Index-based conditions need the index OHLCV cache (Indices sync); volume indicators are not meaningful on indexes.
                </p>

                <h3 className="h6 mt-4">Lookback</h3>
                <p className="small text-muted">
                    Period-style parameters (SMA, EMA, RSI, and similar) allow a minimum of{' '}
                    <strong>{meta?.param_min_period ?? 1}</strong>
                    {' '}
                    (for example SMA/EMA period 1 uses the latest close).
                    Min sessions required for a stock equal the
                    {' '}
                    <em>maximum</em>
                    {' '}
                    lookback implied by the periods in your conditions — not a fixed floor like 20.
                    Symbols with fewer cached OHLCV sessions than that lookback are skipped.
                    “EMA 50” needs 50 stored bars (sessions), not 50 calendar days.
                    52-week high/low use up to 252 sessions when available, otherwise all available history (min 1 session).
                </p>

                <h3 className="h6 mt-4">Sharing &amp; import</h3>
                <p className="small text-muted mb-4">
                    Turn on <strong>Share with your other portfolios</strong> when editing a screener to list it under Shared screens
                    for portfolios on the <em>same account</em> only (not visible to other users).
                    Import copies conditions into My screens as a private local screener (schedule off; watchlist scope becomes holdings).
                </p>

                <h3 className="h6 mt-4">Stacked run results</h3>
                <p className="small text-muted mb-4">
                    On a screener’s editor, use <strong>Show stacked results</strong> under Run history to load an on-demand overlay of completed runs (same latest-30 window).
                    Rows are the unique matched symbols; columns are runs (oldest→newest left to right).
                    Green = hit that run; the badge next to the symbol is how many runs hit; numbers inside green cells are consecutive-hit streaks (reset after a grey miss).
                </p>

                <h3 className="h6 mt-4">Backtest</h3>
                <p className="small text-muted mb-4">
                    Available for <strong>all scopes</strong> — holdings, watchlist, all equities and index constituents — via the <strong>Backtest</strong> split button (dropdown picks 1 year / 6 months / 3 months / 1 month / 15 days).
                    The engine walks each weekday from the start date to today (weekends skipped), treating that day as “today” and using only OHLCV on or before it.
                    Under the hood it loads each stock&rsquo;s history once, computes every indicator series once, and answers all dates from those series — so even a 1-year backtest on the full equity universe stays fast.
                    Results appear in a stacked matrix like stacked run results.
                    {' '}
                    <strong>Results are saved in the database per date</strong> (time of day is irrelevant — one result per screener per date):
                    re-running a backtest reuses saved dates and only computes missing ones, and saved results reappear when you reopen the editor.
                    Completed runs (scheduled cron or manual) also save into the same per-date results, so a screener that runs nightly
                    builds its backtest matrix as it goes — the last completed run of a date wins.
                    Editing the screener&rsquo;s conditions or scope invalidates saved backtest results; <strong>Clear history</strong> also deletes them.
                </p>

                <h3 className="h6 mt-4">Supported indicators</h3>
                <p className="small text-muted mb-3">
                    “Min sessions (defaults)” is how many bars are needed when you leave parameters at their default values.
                    Lower the period (down to {meta?.param_min_period ?? 1}) and the requirement shrinks with it.
                </p>
                {INDICATOR_GROUPS.map((group) => (
                    <div key={group.title} className="mb-4">
                        <h4 className="h6 mb-1">{group.title}</h4>
                        <p className="small text-muted mb-2">{group.blurb}</p>
                        <div className="table-responsive">
                            <table className="table table-sm mb-0 lido-guide-indicator-table">
                                <thead>
                                    <tr>
                                        <th>Indicator</th>
                                        <th>Definition</th>
                                        <th>Parameters</th>
                                        <th>Min sessions (defaults)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {group.ids.map((id) => {
                                        const ind = indicatorById[id];
                                        if (!ind) {
                                            return null;
                                        }
                                        const investopediaUrl = INVESTOPEDIA_LINKS[id];
                                        return (
                                            <tr key={id}>
                                                <td className="text-nowrap">
                                                    <code>{ind.id}</code> — {ind.label}
                                                    {investopediaUrl && (
                                                        <a
                                                            href={investopediaUrl}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="lido-indicator-info-link ms-1"
                                                            title={`${ind.label} on Investopedia (opens in new tab)`}
                                                            aria-label={`${ind.label} definition on Investopedia`}
                                                        >
                                                            ⓘ
                                                        </a>
                                                    )}
                                                </td>
                                                <td className="small">
                                                    <IndicatorDefinition text={INDICATOR_DEFINITIONS[id]} />
                                                </td>
                                                <td className="small text-muted">
                                                    {formatParams(ind.params) || '—'}
                                                </td>
                                                <td>{ind.min_bars ?? '—'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ))}

                <p className="small text-muted mb-0">
                    Max {meta?.max_conditions ?? 40} conditions, nesting depth {meta?.max_nesting ?? 4}.
                    Universe runs scan in chunks of {meta?.chunk_size ?? 150} symbols.
                </p>
            </div>
        </div>
    );
}
