import React, { useEffect, useMemo, useState } from 'react';
import api from '../../api';

const INDICATOR_GROUPS = [
    {
        title: 'Price & volume (bar fields)',
        ids: ['close', 'open', 'high', 'low', 'volume', 'change_pct', 'high_n', 'low_n', 'range_pct'],
        blurb: 'Latest OHLCV values, session change %, rolling highs/lows, and intraday range on the latest bar.',
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
                    Build nested <strong>AND</strong>/<strong>OR</strong> groups, pick a scope (holdings, watchlist, or all equities),
                    and compare indicators to constants or other indicators.
                </p>

                <h3 className="h6 mt-4">Scopes</h3>
                <ul className="small mb-4">
                    {(meta?.scopes || []).map((s) => (
                        <li key={s.id}><strong>{s.label}</strong></li>
                    ))}
                </ul>

                <h3 className="h6">Operators</h3>
                <p className="small text-muted">
                    {(meta?.operators || []).map((op) => op.label).join(' · ')}
                </p>

                <h3 className="h6 mt-4">Lookback</h3>
                <p className="small text-muted">
                    Each indicator needs a minimum number of OHLCV <em>sessions</em> (rows in price history).
                    The screener skips symbols with insufficient history. Periods like “EMA 50” mean 50 stored bars, not calendar days.
                </p>

                <h3 className="h6 mt-4">Sharing &amp; import</h3>
                <p className="small text-muted mb-4">
                    Turn on <strong>Share with other portfolios</strong> when editing a screener to list it under Shared screens.
                    Import copies conditions into My screens as a private screener (schedule off; watchlist scope becomes holdings).
                </p>

                <h3 className="h6 mt-4">Supported indicators</h3>
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
                                        <th>Min sessions</th>
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
