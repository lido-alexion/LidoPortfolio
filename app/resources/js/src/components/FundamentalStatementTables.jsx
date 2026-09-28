import React, { useEffect, useState } from 'react';

const ADVANCED_STORAGE_KEY = 'lido.fundamentals.advancedExpanded';

function StatementTable({ title, rows, periods }) {
    if (!rows?.length || !periods?.length) {
        return null;
    }

    return (
        <div className="mb-3">
            {title ? <h4 className="h6 mb-2">{title}</h4> : null}
            <div className="table-responsive">
                <table className="table table-sm table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Metric</th>
                            {periods.map((p) => (
                                <th key={p} scope="col" className="text-end text-nowrap small">
                                    {p}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows
                            .filter((row) => row.cells?.some((c) => c.value != null))
                            .map((row) => (
                                <tr key={row.fact_key}>
                                    <td className="small">{row.label || row.fact_key}</td>
                                    {row.cells.map((cell) => (
                                        <td key={`${row.fact_key}-${cell.period_end}`} className="text-end small font-monospace">
                                            {cell.value != null
                                                ? (
                                                    <>
                                                        {Number(cell.value).toLocaleString(undefined, { maximumFractionDigits: 2 })}
                                                        {cell.yoy_pct != null ? (
                                                            <span className="text-muted ms-1">
                                                                ({cell.yoy_pct > 0 ? '+' : ''}{Number(cell.yoy_pct).toFixed(1)}% YoY)
                                                            </span>
                                                        ) : null}
                                                    </>
                                                )
                                                : '—'}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function FundamentalStatementTables({ history, cadence, onCadenceChange, preferenceKey = ADVANCED_STORAGE_KEY }) {
    const [advancedOpen, setAdvancedOpen] = useState(false);

    useEffect(() => {
        try {
            setAdvancedOpen(window.localStorage.getItem(preferenceKey) === '1');
        } catch {
            setAdvancedOpen(false);
        }
    }, [preferenceKey]);

    const toggleAdvanced = () => {
        setAdvancedOpen((open) => {
            const next = !open;
            try {
                window.localStorage.setItem(preferenceKey, next ? '1' : '0');
            } catch {
                // ignore
            }
            return next;
        });
    };

    if (!history) {
        return null;
    }

    const periods = history.periods || [];
    const basicRows = history.sections?.basic?.length
        ? history.sections.basic
        : (history.rows || []).filter((r) => r.section !== 'advanced');
    const advancedRows = history.sections?.advanced?.length
        ? history.sections.advanced
        : (history.rows || []).filter((r) => r.section === 'advanced');

    return (
        <div>
            <div className="btn-group btn-group-sm mb-2" role="group" aria-label="Statement cadence">
                {['quarterly', 'annual'].map((id) => (
                    <button
                        key={id}
                        type="button"
                        className={`btn ${cadence === id ? 'btn-primary' : 'btn-outline-primary'}`}
                        onClick={() => onCadenceChange(id)}
                    >
                        {id === 'quarterly' ? 'Quarterly' : 'Annual'}
                    </button>
                ))}
            </div>
            <StatementTable title="Basic financial data" rows={basicRows} periods={periods} />
            {advancedRows.length > 0 ? (
                <div>
                    <button
                        type="button"
                        className="btn btn-sm btn-link px-0 mb-2"
                        onClick={toggleAdvanced}
                        aria-expanded={advancedOpen}
                    >
                        {advancedOpen ? 'Hide' : 'Show'} advanced financial data ({advancedRows.length} rows)
                    </button>
                    {advancedOpen ? (
                        <StatementTable title="Advanced financial data" rows={advancedRows} periods={periods} />
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
