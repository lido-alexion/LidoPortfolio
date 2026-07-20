import React, { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { enrichCompareRows } from '../../utils/screenerRunCompare';

/**
 * Sticky-first-column matrix of stocks × screener runs (green = hit).
 */
export default function ScreenerRunsCompareTable({ matrix, onSelectRun }) {
    const columns = matrix?.columns || [];
    const rows = useMemo(
        () => enrichCompareRows(matrix?.rows || []),
        [matrix?.rows],
    );

    if (columns.length === 0) {
        return (
            <p className="small text-muted mb-0">
                No completed runs to compare yet.
            </p>
        );
    }

    if (rows.length === 0) {
        return (
            <p className="small text-muted mb-0">
                Completed runs have no matched stocks to stack.
            </p>
        );
    }

    return (
        <div className="lido-screener-compare-scroll">
            <table className="table table-sm mb-0 lido-screener-compare-table">
                <thead>
                    <tr>
                        <th className="lido-screener-compare-sticky">Stock</th>
                        {columns.map((col) => (
                            <th key={col.id} className="lido-screener-compare-run text-center">
                                {typeof onSelectRun === 'function' ? (
                                    <button
                                        type="button"
                                        className="btn btn-link btn-sm p-0 fw-semibold"
                                        onClick={() => onSelectRun(col.id)}
                                    >
                                        #{col.id}
                                    </button>
                                ) : (
                                    <div className="fw-semibold">#{col.id}</div>
                                )}
                                <div className="small fw-normal text-muted text-nowrap">
                                    {col.trigger_label}
                                    {' · '}
                                    {col.matched}
                                    {' hit'}
                                    {col.matched === 1 ? '' : 's'}
                                </div>
                                <div className="small fw-normal text-muted text-nowrap">
                                    {col.when_label}
                                </div>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.symbol}>
                            <th scope="row" className="lido-screener-compare-sticky">
                                <div className="d-flex align-items-baseline gap-1 flex-wrap">
                                    {row.symbol ? (
                                        <Link to={`/watchlist/${encodeURIComponent(row.symbol)}`}>
                                            {row.symbol}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                    <span className="badge text-bg-secondary">{row.count}</span>
                                </div>
                                {row.name ? (
                                    <div className="small text-muted text-truncate" style={{ maxWidth: 160 }}>
                                        {row.name}
                                    </div>
                                ) : null}
                            </th>
                            {row.presence.map((hit, index) => {
                                const mark = row.streaks[index];
                                return (
                                    <td
                                        key={`${row.symbol}-${columns[index]?.id ?? index}`}
                                        className={`lido-screener-compare-cell text-center${hit ? ' is-hit' : ' is-miss'}`}
                                    >
                                        {hit ? mark : ''}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
