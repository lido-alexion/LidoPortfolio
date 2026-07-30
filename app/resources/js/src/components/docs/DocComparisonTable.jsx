import React from 'react';

/**
 * Responsive comparison table for contrasting concepts.
 * @param {{
 *   caption?: string,
 *   headers: string[],
 *   rows: Array<string[]>,
 * }} props
 */
export default function DocComparisonTable({ caption, headers = [], rows = [] }) {
    if (!headers.length || !rows.length) {
        return null;
    }

    return (
        <div className="lido-docs-table-wrap mb-3">
            <table className="table table-sm table-bordered align-middle lido-docs-table mb-0">
                {caption ? <caption className="caption-top small fw-semibold">{caption}</caption> : null}
                <thead>
                    <tr>
                        {headers.map((header) => (
                            <th key={header} scope="col">{header}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, rowIdx) => (
                        <tr key={`row-${rowIdx}`}>
                            {row.map((cell, cellIdx) => (
                                <td key={`cell-${rowIdx}-${cellIdx}`}>{cell}</td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
