import React, { useMemo } from 'react';
import { Link } from 'react-router-dom';

function isDarkTheme() {
    if (typeof document === 'undefined') {
        return false;
    }
    const theme = document.documentElement.getAttribute('data-theme');
    if (theme === 'dark') {
        return true;
    }
    if (theme === 'light') {
        return false;
    }
    return window.matchMedia?.('(prefers-color-scheme: dark)')?.matches ?? false;
}

/**
 * Heatmap by % (0–100). Dark mode uses muted/darker greens and ambers.
 */
export function marketDepthHeatmapStyle(pct) {
    if (pct == null || Number.isNaN(Number(pct))) {
        return {
            backgroundColor: 'var(--lido-bg-elevated, #f3f4f6)',
            color: 'var(--lido-text-muted, #6b7280)',
        };
    }
    const t = Math.max(0, Math.min(100, Number(pct))) / 100;
    const dark = isDarkTheme();
    const hue = 28 + t * 90;
    const sat = dark ? 42 + t * 10 : 72 + t * 8;
    const light = dark ? 26 + t * 10 : 58 - t * 18;
    return {
        backgroundColor: `hsl(${hue}, ${sat}%, ${light}%)`,
        color: dark ? '#e8ecef' : '#1a1a1a',
    };
}

/**
 * @param {{
 *   data: object,
 *   valueMode?: 'pct'|'count',
 *   bare?: boolean,
 *   title?: string,
 *   titleTo?: string|null,
 *   hideFooter?: boolean,
 *   className?: string,
 * }} props
 */
export default function MarketDepthTable({
    data,
    valueMode = 'pct',
    bare = false,
    title,
    titleTo = null,
    hideFooter = false,
    className = '',
}) {
    const columns = data?.columns ?? [];
    const rows = data?.rows ?? [];

    const heading = title === '' ? null : (title ?? data?.title ?? 'Market Breadth');
    const asOf = data?.as_of_date ? `As of ${data.as_of_date}` : null;

    const titleNode = useMemo(() => {
        if (!heading) {
            return null;
        }
        if (titleTo) {
            return (
                <Link to={titleTo} className="lido-market-depth-title-link h6 mb-0 text-decoration-none">
                    {heading}
                </Link>
            );
        }
        return <h3 className="h6 mb-0">{heading}</h3>;
    }, [heading, titleTo]);

    if (!rows.length || !columns.length) {
        return null;
    }

    const table = (
        <>
            {(titleNode || (asOf && !bare)) ? (
                <div className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                    {titleNode}
                    {asOf && !bare ? <span className="text-muted small">{asOf}</span> : null}
                </div>
            ) : null}
            <div className="table-responsive">
                <table className="table table-sm mb-0 lido-market-depth-table align-middle">
                    <thead>
                        <tr>
                            <th scope="col" className="lido-market-depth-index-col text-muted fw-normal">
                                Index
                            </th>
                            {columns.map((col) => (
                                <th
                                    key={col.key}
                                    scope="col"
                                    className="text-center text-nowrap fw-semibold lido-market-depth-metric-col"
                                >
                                    {col.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.symbol}>
                                <th scope="row" className="lido-market-depth-index-col">
                                    <div className="fw-semibold lh-sm">{row.name || row.symbol}</div>
                                    <div className="text-muted small lh-1">
                                        {row.eligible != null ? row.eligible : row.constituents}
                                    </div>
                                </th>
                                {columns.map((col) => {
                                    const pct = row[`pct_${col.key}`];
                                    const pass = row[`pass_${col.key}`];
                                    const scanned = row[`scanned_${col.key}`];
                                    const label = valueMode === 'count'
                                        ? (pass == null && scanned == null ? '—' : String(pass ?? 0))
                                        : (pct == null ? '—' : `${pct}%`);
                                    const titleAttr = scanned != null
                                        ? `${pass ?? 0} of ${scanned} (${pct ?? '—'}%)`
                                        : label;
                                    return (
                                        <td key={col.key} className="text-center p-1" title={titleAttr}>
                                            <div
                                                className="lido-market-depth-cell mx-auto"
                                                style={marketDepthHeatmapStyle(pct)}
                                            >
                                                {label}
                                            </div>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {!hideFooter ? (
                <div className="text-muted small mt-2 mb-0">
                    Rising = latest close &gt; prior close. RS 55 = 55-session relative strength vs{' '}
                    {data.benchmark_symbol || 'NIFTY50'} &gt; 0. SMA = close above that average.
                </div>
            ) : null}
        </>
    );

    if (bare) {
        return <div className={className}>{table}</div>;
    }

    return (
        <div className={`card ${className}`.trim()}>
            <div className="card-body py-3">{table}</div>
        </div>
    );
}
