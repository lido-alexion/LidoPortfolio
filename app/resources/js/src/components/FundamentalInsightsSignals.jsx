import React from 'react';

/**
 * FEAT-062 — deterministic + optional AI insight bullets and sector peer table.
 */
export default function FundamentalInsightsSignals({ insights, showAiStatus = false }) {
    if (!insights) {
        return null;
    }

    const ai = insights.ai;
    const deterministic = insights.deterministic || insights;
    const aiInterpretation = insights.ai_interpretation || null;

    const renderSignals = (payload) => (
        <>
            {payload.positive_signals?.length ? (
                <ul className="mb-2 ps-3 text-success">
                    {payload.positive_signals.map((s, idx) => (
                        <li key={`${s.signal_key || 'positive'}-${idx}`}>
                            {s.headline || s.title}
                            {s.evidence && Object.keys(s.evidence).length ? (
                                <span className="text-muted ms-1">({Object.entries(s.evidence).slice(0, 3).map(([key, value]) => `${key}: ${value}`).join(' · ')})</span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            {payload.risk_signals?.length ? (
                <ul className="mb-2 ps-3 text-danger">
                    {payload.risk_signals.map((s, idx) => <li key={`${s.signal_key || 'risk'}-${idx}`}>{s.headline || s.title}</li>)}
                </ul>
            ) : null}
            {payload.watch_items?.length ? (
                <ul className="mb-2 ps-3 text-warning">
                    {payload.watch_items.map((s, idx) => <li key={`${s.signal_key || 'watch'}-${idx}`}>{s.headline || s.title}</li>)}
                </ul>
            ) : null}
        </>
    );

    return (
        <div className="small">
            {deterministic.summary ? (
                <div className="mb-2"><strong>StoX deterministic evidence</strong><div>{deterministic.summary}</div></div>
            ) : null}
            {deterministic.data_sufficiency ? (
                <div className="text-muted mb-2">
                    Data sufficiency: <strong>{deterministic.data_sufficiency.rating}</strong>
                    {deterministic.data_sufficiency.missing_information?.length ? ` · Missing: ${deterministic.data_sufficiency.missing_information.join(', ')}` : ''}
                </div>
            ) : null}
            {renderSignals(deterministic)}
            {aiInterpretation ? (
                <div className="border-start ps-2 mb-2">
                    <strong>AI interpretation</strong>
                    {aiInterpretation.summary ? <div>{aiInterpretation.summary}</div> : null}
                    {renderSignals(aiInterpretation)}
                </div>
            ) : null}
            {showAiStatus && ai?.status ? (
                <p className="text-muted mb-2">
                    AI insights:{' '}
                    {ai.status === 'ok'
                        ? `available (${ai.provider || 'provider'})`
                        : ai.status === 'unavailable'
                            ? 'AI insights are currently unavailable.'
                            : ai.status}
                </p>
            ) : null}
            {(deterministic.follow_up_checks?.length || aiInterpretation?.follow_up_checks?.length) ? (
                <div className="mb-2">
                    <div className="text-muted mb-1">Follow-up checks</div>
                    <ul className="mb-0 ps-3">
                        {[...(deterministic.follow_up_checks || []), ...(aiInterpretation?.follow_up_checks || [])].filter((item, idx, all) => all.indexOf(item) === idx).map((item, idx) => (
                            <li key={`${item}-${idx}`}>{item}</li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {insights.sector_context?.summary ? (
                <p className="mb-2 text-muted">
                    Sector ({insights.sector_context.sector || '—'}
                    {insights.sector_context.peer_count != null
                        ? ` · ${insights.sector_context.peer_count} peers`
                        : ''}
                    ): {insights.sector_context.summary}
                </p>
            ) : null}
            {insights.sector_context?.peer_percentiles?.length ? (
                <table className="table table-sm table-bordered small mb-0">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th className="text-end">Stock</th>
                            <th className="text-end">Peer median</th>
                            <th className="text-end">Percentile</th>
                        </tr>
                    </thead>
                    <tbody>
                        {insights.sector_context.peer_percentiles.map((row) => (
                            <tr key={row.metric_key}>
                                <td>{row.label}</td>
                                <td className="text-end">
                                    {row.value != null
                                        ? Number(row.value).toLocaleString(undefined, { maximumFractionDigits: 2 })
                                        : '—'}
                                </td>
                                <td className="text-end">
                                    {row.peer_median != null
                                        ? Number(row.peer_median).toLocaleString(undefined, { maximumFractionDigits: 2 })
                                        : '—'}
                                </td>
                                <td className="text-end">
                                    {row.percentile != null
                                        ? `${Math.round(Number(row.percentile) * 100)}%`
                                        : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : null}
        </div>
    );
}
