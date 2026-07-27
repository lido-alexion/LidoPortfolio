import React from 'react';

/**
 * Heatmap: % of index constituents meeting each criterion (RS 55 / SMAs).
 * Color: orange (low) → yellow → green (high), matching "Stocks Above" inspiration.
 */
function heatmapStyle(pct) {
    if (pct == null || Number.isNaN(Number(pct))) {
        return { backgroundColor: 'var(--lido-bg-elevated, #f3f4f6)', color: 'var(--lido-text-muted, #6b7280)' };
    }
    const t = Math.max(0, Math.min(100, Number(pct))) / 100;
    // Hue 28 (orange) → 55 (yellow) → 118 (green)
    const hue = 28 + t * 90;
    const sat = 72 + t * 8;
    const light = 58 - t * 18;
    return {
        backgroundColor: `hsl(${hue}, ${sat}%, ${light}%)`,
        color: '#1a1a1a',
    };
}

export default function MarketDepthTable({ data, className = '' }) {
    if (!data?.rows?.length || !data?.columns?.length) {
        return null;
    }

    const title = data.title || 'Stocks Above';
    const asOf = data.as_of_date ? `As of ${data.as_of_date}` : null;

    return (
        <div className={`card ${className}`.trim()}>
            <div className="card-body py-3">
                <div className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                    <h3 className="h6 mb-0">{title}</h3>
                    {asOf ? <span className="text-muted small">{asOf}</span> : null}
                </div>
                <div className="table-responsive">
                    <table className="table table-sm mb-0 lido-market-depth-table align-middle">
                        <thead>
                            <tr>
                                <th scope="col" className="lido-market-depth-index-col text-muted fw-normal">
                                    Index
                                </th>
                                {data.columns.map((col) => (
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
                            {data.rows.map((row) => (
                                <tr key={row.symbol}>
                                    <th scope="row" className="lido-market-depth-index-col">
                                        <div className="fw-semibold lh-sm">{row.name || row.symbol}</div>
                                        {row.constituents != null ? (
                                            <div className="text-muted small lh-1">{row.constituents}</div>
                                        ) : null}
                                    </th>
                                    {data.columns.map((col) => {
                                        const pct = row[`pct_${col.key}`];
                                        const scanned = row[`scanned_${col.key}`];
                                        const label = pct == null ? '—' : `${pct}%`;
                                        const titleAttr = scanned != null
                                            ? `${label} of ${scanned} scanned`
                                            : label;
                                        return (
                                            <td
                                                key={col.key}
                                                className="text-center p-1"
                                                title={titleAttr}
                                            >
                                                <div
                                                    className="lido-market-depth-cell mx-auto"
                                                    style={heatmapStyle(pct)}
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
                <div className="text-muted small mt-2 mb-0">
                    Share of constituents with close above each SMA, or 55-session relative strength vs{' '}
                    {data.benchmark_symbol || 'NIFTY50'} &gt; 0.
                </div>
            </div>
        </div>
    );
}
