import React, { useEffect, useMemo, useState } from 'react';
import api from '../../api';

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
                    Turn on <strong>Share with other portfolios</strong> when editing a screener to list it under Shared screens.
                    Import copies conditions into My screens as a private screener (schedule off; watchlist scope becomes holdings).
                </p>

                <h3 className="h6 mt-4">Stacked run results</h3>
                <p className="small text-muted mb-4">
                    On a screener’s editor, <strong>Stacked run results</strong> overlays completed runs (same latest-30 window as Run history).
                    Rows are the unique matched symbols; columns are runs (oldest→newest left to right).
                    Green = hit that run; the badge next to the symbol is how many runs hit; numbers inside green cells are consecutive-hit streaks (reset after a grey miss).
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
                            <table className="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Indicator</th>
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
                                        return (
                                            <tr key={id}>
                                                <td><code>{ind.id}</code> — {ind.label}</td>
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
