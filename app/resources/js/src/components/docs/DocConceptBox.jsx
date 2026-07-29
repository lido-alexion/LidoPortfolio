import React from 'react';

/**
 * Consistent "Compared against" / fact card layout.
 * @param {{
 *   title: string,
 *   rows: Array<{ label: string, value: React.ReactNode }>,
 *   icon?: string,
 * }} props
 */
export default function DocConceptBox({ title, rows = [], icon = 'bi-sliders' }) {
    if (!rows.length) {
        return null;
    }

    return (
        <div className="card lido-docs-concept-box mb-3">
            <div className="card-header py-2 small fw-semibold">
                <i className={`bi ${icon} me-1`} aria-hidden="true" />
                {title}
            </div>
            <ul className="list-group list-group-flush">
                {rows.map((row) => (
                    <li key={row.label} className="list-group-item py-2 px-3 d-flex gap-3">
                        <span className="text-muted small flex-shrink-0" style={{ minWidth: '6.5rem' }}>
                            {row.label}
                        </span>
                        <span className="small">{row.value}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
